<?php

namespace App\Services;

/** Outcome of a health probe, including a sanitized diagnostic message. */
final class HealthCheckResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly string $message,
        public readonly string $type = "http",
    ) {}

    public static function pass(string $message, string $type = "http"): self
    {
        return new self(true, $message, $type);
    }

    public static function fail(string $message, string $type = "http"): self
    {
        return new self(false, $message, $type);
    }
}
