<?php

namespace Tests\Feature;

use App\Models\{Project, Server};
use App\Services\Deployment\DeploymentLog;
use App\Services\Security\{Redactor, SecretSet};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Logs must be diagnosable, which means they cannot redact ordinary values such
 * as `production` or `database`. Only real secrets are scrubbed.
 */
class SecretSetTest extends TestCase
{
    use RefreshDatabase;

    private function project(array $environment): Project
    {
        $server = Server::create([
            "name" => "Target",
            "hostname" => "host.example.com",
            "cpanel_username" => "demo",
            "ssh_username" => "demo",
            "connection_mode" => "ssh",
            "cpanel_api_token" => "cpanel-token-abc123xyz",
            "ssh_private_key" => "-----BEGIN OPENSSH PRIVATE KEY-----\nkeymaterial\n-----END OPENSSH PRIVATE KEY-----",
        ]);
        return Project::create([
            "name" => "Site",
            "server_id" => $server->id,
            "repository_url" => "https://github.com/example/site",
            "branch" => "main",
            "project_type" => "laravel",
            "remote_path" => "/home/demo/apps/site",
            "health_check_url" => "https://example.com/health",
            "environment_config" => $environment,
            "settings" => ["restart" => "none"],
        ]);
    }

    public function test_only_plausible_secrets_are_collected(): void
    {
        $project = $this->project([
            "APP_ENV" => "production",
            "CACHE_STORE" => "database",
            "OPENAI_API_KEY" => "sk-live-short",
            "DATABASE_URL" => "mysql://user:pw@localhost/db",
            "LONG_OPAQUE_VALUE" => "0123456789abcdef0123",
        ]);

        $secrets = (new SecretSet())->for($project);

        $this->assertContains("sk-live-short", $secrets);
        $this->assertContains("mysql://user:pw@localhost/db", $secrets);
        $this->assertContains("0123456789abcdef0123", $secrets);
        $this->assertContains("cpanel-token-abc123xyz", $secrets);
        $this->assertNotContains("production", $secrets);
        $this->assertNotContains("database", $secrets);
    }

    public function test_log_keeps_readable_output_and_masks_secrets(): void
    {
        $project = $this->project([
            "OPENAI_API_KEY" => "sk-live-short",
            "APP_ENV" => "production",
        ]);
        $log = new DeploymentLog(new Redactor(), new SecretSet());

        $sanitized = $log->sanitize(
            "Deploying in production with OPENAI_API_KEY=sk-live-short; exited 1",
            (new SecretSet())->for($project),
        );

        $this->assertStringContainsString("production", $sanitized);
        $this->assertStringContainsString("exited 1", $sanitized);
        $this->assertStringNotContainsString("sk-live-short", $sanitized);
        $this->assertStringContainsString("[REDACTED]", $sanitized);
    }

    public function test_long_output_is_truncated_keeping_the_tail(): void
    {
        $log = new DeploymentLog(new Redactor(), new SecretSet());
        $sanitized = $log->sanitize(str_repeat("x", 5000) . "FINAL LINE");

        $this->assertLessThanOrEqual(2001, mb_strlen($sanitized));
        $this->assertStringContainsString("FINAL LINE", $sanitized);
        $this->assertStringStartsWith("…", $sanitized);
    }
}
