<?php
namespace App\Services\Security;
use InvalidArgumentException;
final class Input
{
    public static function repository(string $value): string
    {
        if (
            !preg_match(
                "~\Ahttps://github\.com/([A-Za-z0-9][A-Za-z0-9-]{0,38})/([A-Za-z0-9_.-]{1,100}?)(?:\.git)?\z~D",
                $value,
                $m,
            ) ||
            in_array($m[2], [".", ".."])
        ) {
            throw new InvalidArgumentException(
                "Only canonical HTTPS GitHub repository URLs are allowed.",
            );
        }
        return $value;
    }
    public static function branch(string $value): string
    {
        if (
            !preg_match("~\A[A-Za-z0-9][A-Za-z0-9_./-]{0,199}\z~D", $value) ||
            str_contains($value, "..") ||
            str_contains($value, "//") ||
            str_contains($value, "@{") ||
            str_ends_with($value, ".lock") ||
            str_ends_with($value, "/") ||
            str_ends_with($value, ".")
        ) {
            throw new InvalidArgumentException("Invalid branch name.");
        }
        return $value;
    }
    public static function commit(string $value): string
    {
        if (!preg_match("/\A[a-f0-9]{40}\z/D", $value)) {
            throw new InvalidArgumentException(
                "Commit must be a full lowercase SHA.",
            );
        }
        return $value;
    }
    public static function username(string $value): string
    {
        if (!preg_match("/\A[a-zA-Z][a-zA-Z0-9_-]{0,31}\z/D", $value)) {
            throw new InvalidArgumentException("Invalid account username.");
        }
        return $value;
    }
    public static function path(string $value, ?string $username = null): string
    {
        if (
            !preg_match(
                "~\A/home/([A-Za-z][A-Za-z0-9_-]{0,31})/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\z~D",
                $value,
                $m,
            ) ||
            ($username !== null && $m[1] !== self::username($username))
        ) {
            throw new InvalidArgumentException(
                "Use a dedicated absolute directory below the SSH account home; letters, digits, hyphens and underscores only.",
            );
        }
        if (
            in_array(explode("/", $value)[3], [
                ".ssh",
                "mail",
                "etc",
                "ssl",
                "tmp",
                "logs",
                "public_html",
                "www",
            ])
        ) {
            throw new InvalidArgumentException(
                "Use a dedicated deployment directory, not an account system or document root directory.",
            );
        }
        return $value;
    }
    /**
     * A literal fragment used to look for a running process.
     *
     * Only plain characters are accepted and the value is always quoted with
     * escapeshellarg downstream, so it can never become a shell fragment.
     */
    public static function processPattern(string $value): string
    {
        if (
            !preg_match("/\\A[A-Za-z0-9][A-Za-z0-9 ._\\/-]{2,119}\\z/D", $value) ||
            str_contains($value, "..") ||
            str_contains($value, "//")
        ) {
            throw new InvalidArgumentException(
                "Process patterns allow letters, digits, spaces, dots, underscores, slashes and hyphens only.",
            );
        }
        return $value;
    }

    public static function env(array $values): array
    {
        if (
            count($values) > 100 ||
            strlen(json_encode($values, JSON_THROW_ON_ERROR)) > 65536
        ) {
            throw new InvalidArgumentException(
                "Environment is limited to 100 keys and 64 KiB.",
            );
        }
        foreach ($values as $key => $value) {
            if (
                !preg_match("/\A[A-Z_][A-Z0-9_]{0,99}\z/D", (string) $key) ||
                !is_string($value) ||
                strlen($value) > 8192 ||
                preg_match('/[\x00-\x1f\x7f]/', $value)
            ) {
                throw new InvalidArgumentException(
                    "Environment keys must be uppercase identifiers and values single-line strings.",
                );
            }
        }
        return $values;
    }
}
