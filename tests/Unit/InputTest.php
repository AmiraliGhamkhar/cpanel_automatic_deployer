<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Services\Security\Input;
use App\Services\Remote\Command;
class InputTest extends TestCase
{
    public static function unsafe(): array
    {
        return array_map(fn($v) => [$v], [
            "main;id",
            '$(id)',
            "--upload-pack=id",
            "a..b",
            "main\nwhoami",
            "x.lock",
            "a//b",
            "x/",
        ]);
    }
    #[DataProvider("unsafe")]
    public function test_branch_injection_is_rejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Input::branch($value);
    }
    public static function repositories(): array
    {
        return array_map(fn($v) => [$v], [
            "file:///etc/passwd",
            "ssh://github.com/a/b",
            "https://evil.test/a/b",
            "https://github.com@evil.test/a/b",
            "https://github.com/a/b?x=1",
            "https://github.com/a/../b",
        ]);
    }
    #[DataProvider("repositories")]
    public function test_invalid_repository(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Input::repository($value);
    }
    public function test_commands_only_accept_predefined_operations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Command::install("/home/test/apps/site", "npm; curl attacker");
    }
    public function test_paths_cannot_escape_account(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Input::path("/home/other/apps/site", "test");
    }
    public function test_document_root_cannot_be_taken_over(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Input::path("/home/test/public_html", "test");
    }
    public function test_env_newlines_cannot_inject_another_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Input::env(["KEY" => "value\nOTHER=evil"]);
    }
    public function test_process_check_uses_a_quoted_literal(): void
    {
        $command = Command::processCheck("passenger_wsgi node app.js");
        $shell = $command->shell;
        $this->assertStringContainsString("grep -q -F", $shell);
        $this->assertStringContainsString("'passenger_wsgi node app.js'", $shell);
        // Everything the pattern contributes is a quoted literal after grep.
        $patternPart = substr($shell, (int) strpos($shell, "grep"));
        $this->assertStringNotContainsString("$(", $patternPart);
        $this->assertStringNotContainsString(";", $patternPart);
        $this->assertStringNotContainsString("|", $patternPart);
    }

    public static function unsafe_process_patterns(): array
    {
        return array_map(fn($v) => [$v], [
            "app; rm -rf /",
            "$(id)",
            "app`id`",
            "app|cat /etc/passwd",
            "app && whoami",
            "> /etc/passwd",
            "../../etc/passwd",
            "a",
        ]);
    }

    #[DataProvider("unsafe_process_patterns")]
    public function test_process_pattern_injection_is_rejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Command::processCheck($value);
    }

    public function test_in_place_activation_requires_a_managed_release(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Command::inPlaceActivate(
            "/home/test/apps/site",
            "/home/test/apps/other/releases/1",
            1,
            1,
        );
    }

    public function test_in_place_activation_archives_before_deleting(): void
    {
        $shell = Command::inPlaceActivate(
            "/home/test/apps/site",
            "/home/test/apps/site/releases/12",
            1,
            12,
        )->shell;
        $this->assertMatchesRegularExpression(
            "/tar --exclude=\.env -czf .*inplace-12\.tar\.gz.*&&.*rm -rf.*&&.*cp -a .*releases\/12\/\..*app\//s",
            $shell,
        );
        $this->assertStringNotContainsString("ln -s", $shell);
        $this->assertStringNotContainsString("sudo", $shell);
    }

    public function test_release_verification_rejects_unknown_file_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Command::verifyRelease("/home/test/apps/site/releases/1", ["../../etc/passwd"]);
    }

    public function test_clone_uses_pinned_sha_and_timeout(): void
    {
        $c = Command::clone(
            "https://github.com/example/site",
            "main",
            str_repeat("a", 40),
            "/home/test/apps/site/releases/1",
        )->shell;
        $this->assertStringContainsString("timeout 300 git clone", $c);
        $this->assertStringContainsString("git merge-base --is-ancestor", $c);
        $this->assertStringNotContainsString("sudo", $c);
    }
}
