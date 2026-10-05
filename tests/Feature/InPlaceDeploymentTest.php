<?php

namespace Tests\Feature;

use App\Models\{Project, Server, User};
use App\Services\Deployment\{DeploymentService, RollbackDeploymentService};
use App\Services\Remote\GitHubService;
use App\Services\{HealthCheckEngine, HealthCheckResult};
use App\Jobs\DeployProjectJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\RecordingSsh;
use Tests\TestCase;

/**
 * In-place releases exist for hosts where a symlinked document root is not
 * served reliably. They are destructive by nature, so the safety properties are
 * asserted explicitly: the live directory is archived before it is touched and
 * rollback copies a verified older release back in.
 */
class InPlaceDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private RecordingSsh $ssh;

    private function deployment(Project $project, User $user): \App\Models\Deployment
    {
        $d = $project->deployments()->create([
            "triggered_by" => $user->id,
            "branch" => "main",
        ]);
        $project->update(["active_deployment_id" => $d->id]);
        return $d;
    }

    private function setup(string $strategy = "in_place"): array
    {
        $user = User::create([
            "name" => "Admin",
            "email" => "admin@example.com",
            "password" => "long-testing-password",
            "is_admin" => true,
        ]);
        $server = Server::create([
            "name" => "Target",
            "hostname" => "host.example.com",
            "cpanel_username" => "demo",
            "ssh_username" => "demo",
            "connection_mode" => "ssh",
            "last_health_check_at" => now(),
            "capabilities" => array_fill_keys(
                ["ssh", "sftp", "symlink", "tar", "git", "timeout"],
                ["status" => "available"],
            ),
        ]);
        $project = Project::create([
            "name" => "Site",
            "server_id" => $server->id,
            "repository_url" => "https://github.com/example/site",
            "branch" => "main",
            "project_type" => "static",
            "release_strategy" => $strategy,
            "remote_path" => "/home/demo/apps/site",
            "health_check_url" => "https://example.com/health",
            "settings" => ["restart" => "none"],
        ]);

        $this->ssh = new RecordingSsh();
        $this->app->instance(\App\Services\Remote\SshServiceInterface::class, $this->ssh);

        $github = Mockery::mock(GitHubService::class);
        $github->shouldReceive("commit")->andReturn(str_repeat("a", 40));
        $this->app->instance(GitHubService::class, $github);

        $health = Mockery::mock(HealthCheckEngine::class);
        $health->shouldReceive("check")->andReturn(
            HealthCheckResult::pass("HTTPS request returned HTTP 200."),
        );
        $this->app->instance(HealthCheckEngine::class, $health);

        return [$project, $user];
    }

    public function test_in_place_release_archives_the_live_directory_before_overwriting(): void
    {
        [$project, $user] = $this->setup();
        $deployment = $this->deployment($project, $user);

        new DeployProjectJob($deployment->id)->handle(app(DeploymentService::class));

        $deployment->refresh();
        $this->assertSame("success", $deployment->status);
        $this->assertSame(
            $project->remote_path . "/releases/" . $deployment->id,
            $deployment->release_path,
        );
        $this->assertTrue($this->ssh->has("inplace-" . $deployment->id . ".tar.gz"));

        $activation = $this->ssh->shells()[$this->ssh->indexOf("inplace-" . $deployment->id . ".tar.gz")];
        // Backup happens before deletion, deletion before the copy.
        $backupAt = strpos($activation, "tar --exclude=.env");
        $wipeAt = strpos($activation, "-exec rm -rf");
        $copyAt = strpos($activation, "cp -a");
        $this->assertNotFalse($backupAt);
        $this->assertNotFalse($wipeAt);
        $this->assertNotFalse($copyAt);
        $this->assertLessThan($wipeAt, $backupAt);
        $this->assertLessThan($copyAt, $wipeAt);
        // In-place must never depend on a symlinked document root.
        $this->assertStringNotContainsString("ln -s", $activation);
        $this->assertSame($deployment->id, $project->fresh()->current_deployment_id);
    }

    public function test_in_place_rollback_copies_a_verified_previous_release(): void
    {
        [$project, $user] = $this->setup();

        $first = $this->deployment($project, $user);
        new DeployProjectJob($first->id)->handle(app(DeploymentService::class));
        $this->assertSame("success", $first->fresh()->status);
        $first->refresh();

        $second = $this->deployment($project, $user);
        new DeployProjectJob($second->id)->handle(app(DeploymentService::class));
        $this->assertSame("success", $second->fresh()->status);

        $rollback = app(RollbackDeploymentService::class)->trigger(
            $project->fresh(),
            $user,
            $first->id,
            true,
        );
        $this->assertSame("rollback", $rollback->kind);

        $this->ssh->commands = [];
        new DeployProjectJob($rollback->id)->handle(app(DeploymentService::class));

        $rollback->refresh();
        $this->assertSame("rolled_back", $rollback->status);
        $this->assertSame($first->release_path, $rollback->release_path);
        // The rollback archives the live directory too, so a bad rollback is reversible.
        $this->assertTrue($this->ssh->has("inplace-" . $rollback->id . ".tar.gz"));
        $this->assertTrue($this->ssh->has($first->release_path . "/. "));
        $this->assertSame($rollback->id, $project->fresh()->current_deployment_id);
    }

    public function test_symlink_strategy_still_uses_atomic_activation(): void
    {
        [$project, $user] = $this->setup("symlink");
        $deployment = $this->deployment($project, $user);

        new DeployProjectJob($deployment->id)->handle(app(DeploymentService::class));

        $this->assertSame("success", $deployment->fresh()->status);
        $this->assertTrue($this->ssh->has("mv -Tf -- .current-next current"));
        $this->assertFalse($this->ssh->has("inplace-"));
        $this->assertFalse($this->ssh->has("cp -a"));
    }

    public function test_in_place_is_rejected_when_the_host_has_no_tar(): void
    {
        [$project, $user] = $this->setup();
        $project->server->update([
            "capabilities" => array_fill_keys(
                ["ssh", "sftp", "symlink", "git", "timeout"],
                ["status" => "available"],
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/tar is unavailable/");
        app(DeploymentService::class)->validate($project->fresh());
    }
}
