<?php

namespace App\Services\Backup;

use InvalidArgumentException;

/**
 * Database connection details taken from a project's managed environment.
 *
 * The password is only ever written to a mode-0600 temporary option file on the
 * host (never on a command line, where it would be visible in the process
 * list). Values that cannot be represented safely in a MySQL option file are
 * rejected instead of being silently mangled.
 */
final class DatabaseCredentials
{
    public function __construct(
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
        public readonly string $host,
    ) {}

    /** Build from DATABASE_URL / MYSQL_URL or discrete DB_* variables. */
    public static function fromEnvironment(array $env): ?self
    {
        $url = null;
        foreach (["DATABASE_URL", "MYSQL_URL", "DB_URL"] as $key) {
            if (!empty($env[$key]) && is_string($env[$key])) {
                $url = $env[$key];
                break;
            }
        }
        if ($url !== null) {
            $parts = parse_url($url);
            if ($parts === false || !isset($parts["host"], $parts["path"])) {
                throw new InvalidArgumentException(
                    "DATABASE_URL must look like mysql://user:password@host:3306/database.",
                );
            }
            return new self(
                self::database(ltrim($parts["path"], "/")),
                self::identifier(rawurldecode($parts["user"] ?? ""), "database username"),
                rawurldecode($parts["pass"] ?? ""),
                self::host($parts["host"]),
            );
        }

        if (empty($env["DB_DATABASE"]) || empty($env["DB_USERNAME"])) {
            return null;
        }
        return new self(
            self::database((string) $env["DB_DATABASE"]),
            self::identifier((string) $env["DB_USERNAME"], "database username"),
            (string) ($env["DB_PASSWORD"] ?? ""),
            self::host((string) ($env["DB_HOST"] ?? "localhost")),
        );
    }

    public static function database(string $value): string
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $value)) {
            throw new InvalidArgumentException("Invalid database name.");
        }
        return $value;
    }

    public static function identifier(string $value, string $label): string
    {
        if (!preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $value)) {
            throw new InvalidArgumentException("Invalid " . $label . ".");
        }
        return $value;
    }

    public static function host(string $value): string
    {
        if (!preg_match('/\A[A-Za-z0-9._-]{1,255}\z/D', $value)) {
            throw new InvalidArgumentException(
                "Invalid database host. Unix sockets are not supported; use the host name.",
            );
        }
        return $value;
    }

    public function passwordValid(): bool
    {
        return $this->password !== "" &&
            strlen($this->password) <= 128 &&
            !preg_match('/[\x00-\x1f\x7f"\\\\]/', $this->password);
    }

    /** MySQL option file used with --defaults-extra-file. */
    public function optionFile(): string
    {
        if (!$this->passwordValid()) {
            throw new InvalidArgumentException(
                'Database passwords containing quotes, backslashes or control characters cannot be used with the temporary option file. Use cPanel\'s own backup tool for this database.',
            );
        }
        return "[client]\n" .
            "user=" . $this->username . "\n" .
            "password=\"" . $this->password . "\"\n" .
            "host=" . $this->host . "\n";
    }

    /**
     * Lets the panel refuse to import a dump into a different database or with
     * different credentials. Keyed with APP_KEY so it is not brute-forceable.
     */
    public function fingerprint(): string
    {
        return hash_hmac(
            "sha256",
            implode("|", [$this->database, $this->username, $this->host, $this->password]),
            (string) config("app.key"),
        );
    }
}
