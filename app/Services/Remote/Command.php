<?php
namespace App\Services\Remote;
use App\Services\Security\Input;
final class Command
{
    private function __construct(public readonly string $shell) {}
    private static function q(string $v): string
    {
        return escapeshellarg($v);
    }
    public static function probe(string $name): self
    {
        $probes = [
            "git" => "command -v git",
            "php" => "php -v",
            "composer" => "composer --version",
            "python" => "python3 --version",
            "pip" => "python3 -m pip --version",
            "node" => "node --version",
            "npm" => "npm --version",
            "cron" => "command -v crontab",
            "disk" => "df -Pk .",
            "timeout" => "command -v timeout",
            "tar" => "command -v tar",
            "symlink" => "command -v ln",
            "passenger" => "command -v passenger-config",
        ];
        if (!isset($probes[$name])) {
            throw new \InvalidArgumentException("Unknown diagnostic");
        }
        return new self($probes[$name]);
    }
    public static function initialize(
        string $base,
        int $id,
        string $username,
    ): self {
        Input::path($base, $username);
        $q = self::q($base);
        $marker = self::q($base . "/.control-project-" . $id);
        // Reject symlinked ancestors. Existing directories must already belong to this project or be empty.
        $parts = explode("/", trim($base, "/"));
        $prefix = "";
        $checks = [];
        foreach ($parts as $part) {
            $prefix .= "/" . $part;
            $checks[] = "test ! -L " . self::q($prefix);
        }
        return new self(
            implode(" && ", $checks) .
                " && mkdir -p -- $q && test \"$(cd $q && pwd -P)\" = $q && (test -f $marker || (test -z \"$(ls -A -- $q)\" && touch -- $marker))",
        );
    }
    public static function prepare(string $base, int $id): self
    {
        Input::path($base);
        $r = self::q($base . "/releases");
        $s = self::q($base . "/shared");
        $b = self::q($base . "/backups");
        return new self(
            "test -f " .
                self::q($base . "/.control-project-" . $id) .
                " && test ! -L $r && test ! -L $s && test ! -L $b && mkdir -p -- $r $s $b && chmod 700 -- $s $b",
        );
    }
    public static function clone(
        string $repo,
        string $branch,
        string $sha,
        string $release,
    ): self {
        Input::repository($repo);
        Input::branch($branch);
        Input::commit($sha);
        $r = self::q($release);
        return new self(
            "GIT_TERMINAL_PROMPT=0 timeout 300 git clone --no-checkout --single-branch --branch " .
                self::q($branch) .
                " -- " .
                self::q($repo) .
                " " .
                $r .
                " && cd " .
                $r .
                " && git merge-base --is-ancestor " .
                self::q($sha) .
                " " .
                self::q("origin/" . $branch) .
                " && git checkout --detach " .
                self::q($sha),
        );
    }
    public static function install(string $release, string $operation): self
    {
        $ops = [
            "composer" =>
                "composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader",
            "laravel-clear" => "php artisan config:clear",
            "laravel-cache" =>
                "php artisan config:cache && php artisan view:cache",
            "laravel-storage" =>
                "mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs",
            "migrate" => "php artisan migrate --force --no-interaction",
            "python" =>
                "python3 -m venv .venv && .venv/bin/python -m pip install --disable-pip-version-check -r requirements.txt",
            "pyproject" =>
                "python3 -m venv .venv && .venv/bin/python -m pip install --disable-pip-version-check .",
            "npm" => "npm ci --no-audit --no-fund",
            "build" => "npm run build",
            "passenger" => "mkdir -p tmp && touch tmp/restart.txt",
        ];
        if (!isset($ops[$operation])) {
            throw new \InvalidArgumentException("Unsupported operation");
        }
        return new self(
            "cd " .
                self::q($release) .
                " && timeout 300 sh -c " .
                self::q($ops[$operation]),
        );
    }
    public static function linkEnvironment(string $base, string $release): self
    {
        return new self(
            "test ! -L " .
                self::q($base . "/shared/.env") .
                " && chmod 600 -- " .
                self::q($base . "/shared/.env") .
                " && ln -sfn -- " .
                self::q($base . "/shared/.env") .
                " " .
                self::q($release . "/.env"),
        );
    }
    public static function removeGitMetadata(string $release): self
    {
        if (
            !preg_match(
                "~\A/home/[A-Za-z][A-Za-z0-9_-]*/[A-Za-z0-9_/-]+/releases/[0-9]+\z~D",
                $release,
            ) ||
            str_contains($release, "..")
        ) {
            throw new \InvalidArgumentException("Invalid managed release");
        }
        return new self(
            "test -d " .
                self::q($release . "/.git") .
                " && test ! -L " .
                self::q($release . "/.git") .
                " && rm -rf -- " .
                self::q($release . "/.git"),
        );
    }
    public static function storage(string $base, string $release): self
    {
        $storage = self::q($base . "/shared/storage");
        $target = self::q($release . "/storage");
        // Keep application uploads, sessions and logs outside immutable releases.
        return new self(
            "test ! -L " .
                $storage .
                " && mkdir -p -- " .
                self::q($base . "/shared/storage/framework/cache/data") .
                " " .
                self::q($base . "/shared/storage/framework/sessions") .
                " " .
                self::q($base . "/shared/storage/framework/views") .
                " " .
                self::q($base . "/shared/storage/logs") .
                " " .
                self::q($base . "/shared/storage/app/public") .
                " && test ! -L " .
                $target .
                " && if test -d " .
                $target .
                "; then mv -- " .
                $target .
                " " .
                self::q($release . "/storage-repository") .
                "; fi && ln -s -- " .
                $storage .
                " " .
                $target,
        );
    }
    public static function activate(
        string $base,
        string $release,
        int $id,
    ): self {
        $relative = substr($release, strlen($base) + 1);
        if (!preg_match("~\Areleases/[0-9]+\z~D", $relative)) {
            throw new \InvalidArgumentException("Invalid release path");
        }
        return new self(
            "cd " .
                self::q($base) .
                " && test -d " .
                self::q($release) .
                " && test ! -L " .
                self::q($release) .
                " && test -f " .
                self::q(".control-project-" . $id) .
                " && test ! -e .current-next && test ! -L .current-next && ln -s -- " .
                self::q($relative) .
                " .current-next && mv -Tf -- .current-next current",
        );
    }
    public static function archive(
        string $base,
        string $release,
        int $backup,
    ): self {
        return new self(
            "test ! -e " .
                self::q($base . "/backups/" . $backup . ".tar.gz") .
                " && umask 077 && timeout 300 tar --exclude=.git --exclude=.env -czf " .
                self::q($base . "/backups/" . $backup . ".tar.gz") .
                " -C " .
                self::q($release) .
                " .",
        );
    }
}
