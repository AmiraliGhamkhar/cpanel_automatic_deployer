<?php

namespace App\Services;

use App\Models\Project;
use App\Services\Remote\{Command, SshServiceInterface};
use App\Services\Security\PublicHttp;

/**
 * Capability-aware health verification.
 *
 * Only a successful probe may mark a deployment successful. Attempts are
 * bounded (never an infinite retry loop) and every failure reports a sanitized
 * reason that the deployment log can store.
 */
class HealthCheckEngine
{
    private const MAX_ATTEMPTS = 5;

    private const MAX_DELAY_SECONDS = 30;

    public function __construct(private PublicHttp $http) {}

    /** Probe the project using its configured health strategy. */
    public function check(Project $project, ?SshServiceInterface $ssh = null): HealthCheckResult
    {
        $type = (string) $project->setting("health_type", "http");
        $attempts = (int) $project->setting("health_attempts", 3);
        $delay = (int) $project->setting("health_delay", 2);
        $attempts = max(1, min(self::MAX_ATTEMPTS, $attempts));
        $delay = max(0, min(self::MAX_DELAY_SECONDS, $delay));

        $result = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1 && $delay > 0) {
                sleep($delay * ($attempt - 1));
            }
            $result = $this->attempt($project, $type, $ssh);
            if ($result->passed) {
                return new HealthCheckResult(
                    true,
                    $result->message .
                        ($attempt > 1 ? " (attempt " . $attempt . ")" : ""),
                    $type,
                );
            }
        }

        return new HealthCheckResult(
            false,
            ($result?->message ?? "No probe was executed.") .
                " after " .
                $attempts .
                " attempt(s)",
            $type,
        );
    }

    private function attempt(
        Project $project,
        string $type,
        ?SshServiceInterface $ssh,
    ): HealthCheckResult {
        return match ($type) {
            "http" => $this->http($project->health_check_url),
            "tcp" => $this->tcp($project),
            "process" => $this->process($project, $ssh),
            default => HealthCheckResult::fail(
                "Unsupported health check type.",
                $type,
            ),
        };
    }

    /** HTTPS probe. Redirects are rejected: a redirect is not a healthy response. */
    public function http(string $url): HealthCheckResult
    {
        try {
            $status = $this->http->get($url)->status();
        } catch (\Throwable $e) {
            return HealthCheckResult::fail(
                "HTTPS request failed: " . $e->getMessage(),
                "http",
            );
        }
        return $status === 200
            ? HealthCheckResult::pass("HTTPS request returned HTTP 200.", "http")
            : HealthCheckResult::fail(
                "HTTPS request returned HTTP " . $status . ".",
                "http",
            );
    }

    /** TCP connect probe against the health host; DNS is pinned and private ranges are rejected. */
    public function tcp(Project $project): HealthCheckResult
    {
        $parts = parse_url($project->health_check_url);
        $host = $parts["host"] ?? "";
        $port = (int) $project->setting("health_port", $parts["port"] ?? 443);
        if ($host === "" || $port < 1 || $port > 65535) {
            return HealthCheckResult::fail(
                "A hostname and a port between 1 and 65535 are required for a TCP check.",
                "tcp",
            );
        }
        try {
            $ip = $this->http->resolve($host);
        } catch (\Throwable $e) {
            return HealthCheckResult::fail(
                "TCP target rejected: " . $e->getMessage(),
                "tcp",
            );
        }
        $errno = 0;
        $error = "";
        $socket = @stream_socket_client(
            sprintf("tcp://%s:%d", str_contains($ip, ":") ? "[" . $ip . "]" : $ip, $port),
            $errno,
            $error,
            8,
        );
        if ($socket === false) {
            return HealthCheckResult::fail(
                "TCP connect to " . $host . ":" . $port . " failed.",
                "tcp",
            );
        }
        fclose($socket);
        return HealthCheckResult::pass(
            "TCP connect to " . $host . ":" . $port . " succeeded.",
            "tcp",
        );
    }

    /**
     * Process check: confirms a provider-managed application process exists.
     * Requires an already-connected SSH session; the pattern is a validated
     * literal, never a shell fragment.
     */
    public function process(
        Project $project,
        ?SshServiceInterface $ssh,
    ): HealthCheckResult {
        $pattern = (string) $project->setting("health_process", "");
        if ($pattern === "") {
            return HealthCheckResult::fail(
                "No process pattern is configured for the process health check.",
                "process",
            );
        }
        if (!$ssh) {
            return HealthCheckResult::fail(
                "The process health check requires SSH, which is not configured for this server.",
                "process",
            );
        }
        try {
            $ssh->run(Command::processCheck($pattern));
        } catch (\Throwable) {
            return HealthCheckResult::fail(
                "No matching application process was found on the host.",
                "process",
            );
        }
        return HealthCheckResult::pass(
            "The configured application process is running.",
            "process",
        );
    }

    /** Convenience wrapper used by simple callers. */
    public function passes(Project $project, ?SshServiceInterface $ssh = null): bool
    {
        return $this->check($project, $ssh)->passed;
    }
}
