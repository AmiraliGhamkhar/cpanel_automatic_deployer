<?php

namespace App\Services\Deployment;

use App\Models\Deployment;
use App\Services\Remote\RemoteCommandFailed;
use App\Services\Security\{Redactor, SecretSet};

/**
 * Writes the structured, sanitized deployment log.
 *
 * Every step produces one JSON line: time, step, status, message, duration.
 * Remote output is captured so failures are diagnosable, but it is always
 * redacted and truncated first, and the log as a whole is size-capped so a
 * chatty build cannot bloat the row.
 */
class DeploymentLog
{
    /** Sanitized remote output stored per step (tail is kept). */
    private const MAX_MESSAGE_CHARS = 2000;

    /** Upper bound for a stored log; oldest lines are dropped first. */
    private const MAX_LOG_BYTES = 524288;

    private const MAX_LINES = 500;

    public function __construct(
        private Redactor $redactor,
        private SecretSet $secrets,
    ) {}

    public function step(
        Deployment $deployment,
        string $title,
        callable $operation,
    ): mixed {
        $project = $deployment->project;
        $secrets = $this->secrets->for($project);
        $started = microtime(true);
        $this->append($deployment, $title, "running", 0, "Started", $secrets);

        try {
            $result = $operation();
            $this->append(
                $deployment,
                $title,
                "success",
                microtime(true) - $started,
                $this->summary($result, $secrets),
                $secrets,
            );
            return $result;
        } catch (\Throwable $e) {
            $reason = $this->reason($e, $secrets);
            $this->append(
                $deployment,
                $title,
                "failed",
                microtime(true) - $started,
                $reason,
                $secrets,
            );
            // Persisted immediately so the failed step survives a dead worker.
            $deployment->update([
                "failure_step" => mb_substr($title, 0, 200),
                "failure_detail" => $reason,
            ]);
            throw $e;
        }
    }

    /** Redacts known secrets, collapses whitespace and keeps the useful tail. */
    public function sanitize(string $text, array $secrets = []): string
    {
        $redacted = $this->redactor->redact($text, $secrets);
        $redacted = trim(preg_replace("/[ \t]+/", " ", $redacted) ?? $redacted);
        $redacted = preg_replace("/\n{3,}/", "\n\n", $redacted) ?? $redacted;
        if (mb_strlen($redacted) <= self::MAX_MESSAGE_CHARS) {
            return $redacted;
        }
        return "…" . mb_substr($redacted, -self::MAX_MESSAGE_CHARS + 1);
    }

    /** Sanitized, truncated description of a successful step's output. */
    private function summary(mixed $result, array $secrets): string
    {
        if (!is_string($result) || trim($result) === "") {
            return "Completed";
        }
        return $this->sanitize($result, $secrets);
    }

    private function reason(\Throwable $e, array $secrets): string
    {
        if ($e instanceof RemoteCommandFailed) {
            $output = $this->sanitize($e->output, $secrets);
            return $output === ""
                ? "Remote command exited with status " . $e->exitStatus . "."
                : "Remote command exited with status " .
                    $e->exitStatus .
                    ". Output: " .
                    $output;
        }
        return $this->sanitize(
            $e->getMessage() !== ""
                ? $e->getMessage()
                : "Operation failed without a message.",
            $secrets,
        );
    }

    private function append(
        Deployment $d,
        string $title,
        string $status,
        float $duration,
        string $message,
        array $secrets,
    ): void {
        $line = json_encode(
            [
                "time" => now()->toIso8601String(),
                "step" => $title,
                "status" => $status,
                "message" => $message,
                "duration" => round($duration, 2),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        // Only the message and duration vary here and the message is sanitized
        // before encoding, so the JSON structure is never corrupted by redaction.
        if ($line === false) {
            return;
        }
        $lines = array_values(
            array_filter(explode("\n", (string) $d->log_output), fn ($l) => $l !== ""),
        );
        $lines[] = $line;
        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, -self::MAX_LINES);
        }
        $output = implode("\n", $lines) . "\n";
        while (strlen($output) > self::MAX_LOG_BYTES && count($lines) > 1) {
            array_shift($lines);
            $output = implode("\n", $lines) . "\n";
        }
        $d->update(["log_output" => $output]);
    }
}
