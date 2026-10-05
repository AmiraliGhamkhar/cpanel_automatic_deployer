<?php
// Run with PHP 8.4+: php tests/security-smoke.php (no Composer required).
require __DIR__ . "/../app/Services/Security/Input.php";
require __DIR__ . "/../app/Services/Security/Redactor.php";
require __DIR__ . "/../app/Services/Remote/Command.php";
use App\Services\Security\{Input, Redactor};
use App\Services\Remote\Command;
$count = 0;
function check(bool $condition, string $message): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $count++;
}
function reject(callable $operation): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        check(true, "rejected");
        return;
    }
    throw new RuntimeException("Unsafe input was accepted.");
}
foreach (
    [
        "main;id",
        '$(id)',
        "--upload-pack=id",
        "a..b",
        "main\nwhoami",
        "x.lock",
        "a//b",
        "x/",
    ]
    as $v
) {
    reject(fn() => Input::branch($v));
}
foreach (
    [
        "file:///etc/passwd",
        "ssh://github.com/a/b",
        "https://evil.test/a/b",
        "https://github.com@evil.test/a/b",
        "https://github.com/a/b?x=1",
    ]
    as $v
) {
    reject(fn() => Input::repository($v));
}
foreach (
    [
        "/home/demo/../x",
        "/etc/cron",
        "/home/demo/public_html",
        "/home/demo/a;id",
        "/home/other/site",
    ]
    as $v
) {
    reject(fn() => Input::path($v, "demo"));
}
reject(fn() => Input::env(["KEY" => "value\nOTHER=evil"]));
reject(fn() => Command::install("/home/demo/site", "rm -rf /"));
check(
    Input::repository("https://github.com/example/site.git") ===
        "https://github.com/example/site.git",
    "valid repo",
);
check(
    Input::path("/home/demo/apps/site", "demo") === "/home/demo/apps/site",
    "valid path",
);
$c = Command::clone(
    "https://github.com/example/site",
    "feature/safe",
    str_repeat("a", 40),
    "/home/demo/apps/site/releases/1",
)->shell;
check(str_contains($c, "timeout 300 git clone"), "Git timeout");
check(str_contains($c, "merge-base --is-ancestor"), "Commit ancestry");
$r = new Redactor()->redact(
    "password=secret\nhttps://user:pass@example.com\n-----BEGIN OPENSSH PRIVATE KEY-----key-----END OPENSSH PRIVATE KEY-----\nknown-value",
    ["known-value"],
);
foreach (["secret", "user:pass", "-----BEGIN", "known-value"] as $v) {
    check(!str_contains($r, $v), "redaction");
}
check(
    str_contains(
        Command::activate(
            "/home/demo/apps/site",
            "/home/demo/apps/site/releases/1",
            1,
        )->shell,
        "mv -Tf",
    ),
    "atomic activation",
);
reject(
    fn() => Command::activate(
        "/home/demo/apps/site",
        "/home/demo/apps/other/releases/1",
        1,
    ),
);
echo "Security smoke checks passed: {$count}\n";
