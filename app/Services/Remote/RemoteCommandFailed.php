<?php

namespace App\Services\Remote;

use RuntimeException;

/**
 * A remote command ran but exited with a non-zero status.
 *
 * The captured output is kept in memory so the deployment log can persist a
 * sanitized, truncated copy. It must never be written to storage raw: the
 * deployment log always routes it through the redactor first.
 */
class RemoteCommandFailed extends RuntimeException
{
    public function __construct(
        public readonly string $output,
        public readonly int $exitStatus,
        public readonly string $label,
    ) {
        parent::__construct(
            sprintf('Remote command failed (exit %d): %s', $exitStatus, $label),
        );
    }
}
