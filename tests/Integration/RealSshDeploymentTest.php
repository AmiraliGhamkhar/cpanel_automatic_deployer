<?php

namespace Tests\Integration;

use App\Models\{Project, Server, User};
use App\Services\Deployment\{DeploymentService, RollbackDeploymentService};
use App\Services\Remote\{Command, GitHubService, SshService, SshServiceInterface};
use App\Services\Security\PublicHttp;
use App\Services\{HealthCheckEngine, HealthCheckResult};
use App\Jobs\DeployProjectJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * End-to-end verification against a real SSH server on the test machine and a
 * real GitHub clone. Everything else (health checks, commit resolution) is
 * mocked so the test is deterministic and offline-safe apart from the clone.
 *
 * Enabled only when CONTROL_SSH_TEST_TARGET is set; see .github/workflows/tests.yml
 * and docs/architecture.md. Loopback targets are refused unless the explicit
 * test-only flag is enabled here.
 */
class RealSshDeploymentTest extends TestCase
{
    use RefreshDatabase;

    /** Root commit of octocat/Hello-World: stable, public and tiny. */
    private const PINNED_SHA = "7fd1a60b01f91b314f59955a4e4d4e80d8edf11d";

    private const REPOSITORY = "https://github.com/octocat/Hello-World";

    private const BRANCH = "master";

    private string $basePath;

    private string $sshUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv("CONTROL_SSH_TEST_TARGET")) {
            $this->markTestSkipped(
                "Set CONTROL_SSH_TEST_TARGET (host:port) to run the real-SSH integration suite.",
            );
        }

        // The only place loopback destinations are permitted. Production keeps
        // control.allow_private_targets = false.
        config(["control.allow_private_targets" => true]);
        $this->sshUser = getenv("CONTROL_SSH_TEST_USER") ?: "runner";
        $this->basePath =
            "/home/" . $this->sshUser . "/deploy-tests-" . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->basePath) && str_contains($this->basePath, "/deploy-tests-")) {
            exec("rm -rf -- " . escapeshellarg($this->basePath) . " 2>/dev/null");
        }
        parent::tearDown();
    }

    private function ssh(): SshService
    {
        return new SshService(new PublicHttp());
    }

    private function port(): int
    {
        $target = (string) getenv("CONTROL_SSH_TEST_TARGET");
        return (int) (explode(":", $target)[1] ?? 22);
    }

    private function server(array $overrides = []): Server
    {
        return Server::create(array_merge([
            "name" => "CI SSH target",
            "hostname" => "127.0.0.1",
            "cpanel_username" => $this->sshUser,
            "ssh_username" => $this->sshUser,
            "ssh_port" => $this->port(),
            "ssh_host_fingerprint" => (string) getenv("CONTROL_SSH_TEST_FINGERPRINT"),
            "ssh_private_key" => (string) getenv("CONTROL_SSH_TEST_KEY"),
            "connection_mode" => "ssh",
            "last_health_check_at" => now(),
            "capabilities" => array_fill_keys(
                ["ssh", "sftp", "symlink", "tar", "git", "timeout"],
                ["status" => "available"],
            ),
        ], $overrides));
    }

    private function admin(): User
    {
        return User::create([
            "name" => "Admin",
            "email" => "admin@example.com",
            "password" => "long-testing-password",
            "is_admin" => true,
        ]);
    }

    private function project(Server $server, User $user, array $overrides = []): Project
    {
        return Project::create(array_merge([
            "name" => "Integration site",
            "server_id" => $server->id,
            "repository_url" => self::REPOSITORY,
            "branch" => self::BRANCH,
            "project_type" => "static",
            "deployment_mode" => "ssh",
            "release_strategy" => "symlink",
            "remote_path" => $this->basePath,
            "health_check_url" => "https://example.com/health",
            "settings" => ["restart" => "none"],
        ], $overrides));
    }

    private function fakeGitAndHealth(): void
    {
        $github = Mockery::mock(GitHubService::class);
        $github->shouldReceive("commit")->andReturn(self::PINNED_SHA);
        $this->app->instance(GitHubService::class, $github);

        $health = Mockery::mock(HealthCheckEngine::class);
        $health->shouldReceive("check")->andReturn(
            HealthCheckResult::pass("HTTPS request returned HTTP 200."),
        );
        $this->app->instance(HealthCheckEngine::class, $health);
    }

    public function test_host_key_pinning_and_real_commands(): void
    {
        // A wrong pinned fingerprint must be refused before authentication.
        try {
            $this->ssh()->connect(
                $this->server([
                    "ssh_host_fingerprint" => "SHA256:" . str_repeat("A", 43),
                ]),
            );
            $this->fail("A mismatched host key was accepted.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("SSH failed", $e->getMessage());
        }

        $ssh = $this->ssh();
        $ssh->connect($this->server());

        $git = $ssh->run(Command::probe("git"));
        $this->assertStringContainsString("git", $git);

        $ssh->run(Command::initialize($this->basePath, 1, $this->sshUser));
        $ssh->run(Command::prepare($this->basePath, 1));
        $this->assertTrue($ssh->exists($this->basePath . "/releases"));
        $this->assertTrue($ssh->exists($this->basePath . "/shared"));
        $this->assertTrue($ssh->exists($this->basePath . "/backups"));

        $ssh->upload($this->basePath . "/shared/probe.txt", "hello\n");
        $this->assertSame("hello\n", $ssh->read($this->basePath . "/shared/probe.txt"));
    }

    public function test_real_release_deployment_and_rollback(): void
    {
        $user = $this->admin();
        $server = $this->server();
        $project = $this->project($server, $user);
        $this->app->instance(SshServiceInterface::class, $this->ssh());
        $this->fakeGitAndHealth();

        $service = app(DeploymentService::class);

        $first = $service->trigger($project, $user, self::BRANCH, self::PINNED_SHA, true);
        new DeployProjectJob($first->id)->handle($service);
        $first->refresh();
        $this->assertSame("success", $first->status, (string) $first->failure_detail);
        $this->assertTrue(is_link($this->basePath . "/current"));
        $this->assertSame(
            "releases/" . $first->id,
            readlink($this->basePath . "/current"),
        );
        $this->assertFileExists($this->basePath . "/current/README");
        // Git metadata must not be served.
        $this->assertDirectoryDoesNotExist(
            $this->basePath . "/releases/" . $first->id . "/.git",
        );

        $second = $service->trigger($project->fresh(), $user, self::BRANCH, self::PINNED_SHA, true);
        new DeployProjectJob($second->id)->handle($service);
        $second->refresh();
        $this->assertSame("success", $second->status, (string) $second->failure_detail);
        $this->assertSame(
            "releases/" . $second->id,
            readlink($this->basePath . "/current"),
        );

        $rollback = app(RollbackDeploymentService::class)->trigger(
            $project->fresh(),
            $user,
            $first->id,
            true,
        );
        new DeployProjectJob($rollback->id)->handle($service);
        $rollback->refresh();
        $this->assertSame("rolled_back", $rollback->status, (string) $rollback->failure_detail);
        $this->assertSame($first->release_path, $rollback->release_path);
        $this->assertSame(
            "releases/" . $first->id,
            readlink($this->basePath . "/current"),
        );
        $this->assertFileExists($this->basePath . "/current/README");

        // Everything the deployment did is recorded and sanitized.
        $this->assertStringContainsString("Activate release", (string) $rollback->log_output);
        $this->assertStringContainsString("Clone repository", (string) $second->log_output);
    }

    public function test_real_in_place_deployment_archives_before_overwriting(): void
    {
        $user = $this->admin();
        $server = $this->server();
        $project = $this->project($server, $user, ["release_strategy" => "in_place"]);
        $this->app->instance(SshServiceInterface::class, $this->ssh());
        $this->fakeGitAndHealth();

        $service = app(DeploymentService::class);
        $deployment = $service->trigger($project, $user, self::BRANCH, self::PINNED_SHA, true);
        new DeployProjectJob($deployment->id)->handle($service);

        $deployment->refresh();
        $this->assertSame("success", $deployment->status, (string) $deployment->failure_detail);
        $this->assertFileExists($this->basePath . "/app/README");
        $this->assertFalse(is_link($this->basePath . "/app"));

        // A second release must archive the live directory first.
        $second = $service->trigger($project->fresh(), $user, self::BRANCH, self::PINNED_SHA, true);
        new DeployProjectJob($second->id)->handle($service);
        $second->refresh();
        $this->assertSame("success", $second->status, (string) $second->failure_detail);
        $this->assertFileExists($this->basePath . "/backups/inplace-" . $second->id . ".tar.gz");
    }
}
