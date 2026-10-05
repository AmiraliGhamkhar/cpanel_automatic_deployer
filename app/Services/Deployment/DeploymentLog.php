<?php
namespace App\Services\Deployment;
use App\Models\Deployment;
use App\Services\Security\Redactor;
class DeploymentLog
{
    public function step(
        Deployment $deployment,
        string $title,
        callable $operation,
    ): mixed {
        $started = microtime(true);
        $this->append($deployment, $title, "running", 0);
        try {
            $result = $operation();
            $this->append(
                $deployment,
                $title,
                "success",
                microtime(true) - $started,
            );
            return $result;
        } catch (\Throwable $e) {
            $this->append(
                $deployment,
                $title,
                "failed",
                microtime(true) - $started,
            );
            throw $e;
        }
    }
    private function append(
        Deployment $d,
        string $title,
        string $status,
        float $duration,
    ): void {
        // Persist only first-party step names, never remote stdout, HTTP bodies or exception messages.
        $line = json_encode(
            [
                "time" => now()->toIso8601String(),
                "step" => $title,
                "status" => $status,
                "message" =>
                    $status === "failed"
                        ? "Operation failed. Verify provider capabilities, permissions and runtime requirements."
                        : ucfirst($status),
                "duration" => round($duration, 2),
            ],
            JSON_UNESCAPED_SLASHES,
        );
        $d->update([
            "log_output" =>
                ($d->log_output ?? "") .
                app(Redactor::class)->redact($line) .
                "\n",
        ]);
    }
}
