<?php

namespace App\Services\Security;

use App\Models\Project;
use App\Models\Server;

/**
 * Collects the literal secret values that must never reach stored logs.
 *
 * Only values that are plausibly secret are collected: identifiers such as
 * APP_ENV=production or CACHE_STORE=database would otherwise be replaced
 * everywhere and destroy the diagnostic value of the log.
 */
final class SecretSet
{
    /** Shortest value that is treated as a secret purely because of its length. */
    private const MIN_OPAQUE_LENGTH = 12;

    /** Keys whose value is always treated as secret, whatever its length. */
    private const SENSITIVE_KEY = '/(?:KEY|TOKEN|SECRET|PASSWORD|PASSWD|CREDENTIAL|AUTH|DSN|DATABASE_URL|_URL|SALT|PRIVATE)/i';

    public function for(?Project $project = null, ?Server $server = null): array
    {
        $secrets = [];
        $project?->loadMissing('server');
        $server ??= $project?->server;

        foreach ($project?->environment_config ?? [] as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (
                preg_match(self::SENSITIVE_KEY, (string) $key) ||
                strlen($value) >= self::MIN_OPAQUE_LENGTH
            ) {
                $secrets[] = $value;
            }
        }

        foreach ([$server?->cpanel_api_token, $server?->ssh_private_key] as $value) {
            if (is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }

        if ($token = config('services.github.token')) {
            $secrets[] = (string) $token;
        }

        return array_values(array_unique(array_filter($secrets, fn ($v) => strlen($v) >= 4)));
    }
}
