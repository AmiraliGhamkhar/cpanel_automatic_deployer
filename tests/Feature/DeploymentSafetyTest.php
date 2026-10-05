<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Server, Project, User, Deployment};
use App\Services\{CapabilityDetector, HealthCheckEngine};
use App\Services\Remote\{
    SshServiceInterface,
    CpanelServiceInterface,
    GitHubService,
};
use App\Services\Deployment\{DeploymentService, RollbackDeploymentService};
use App\Jobs\DeployProjectJob;
use Mockery;
class DeploymentSafetyTest extends TestCase
{
    use RefreshDatabase;
    private function setupDeployment(): Deployment
    {
        $u = User::create([
            "name" => "Admin",
            "email" => "admin@example.com",
            "password" => "long-test-password",
            "is_admin" => true,
        ]);
        $s = Server::create([
            "name" => "Target",
            "hostname" => "host.example.com",
            "cpanel_username" => "demo",
            "ssh_username" => "demo",
            "connection_mode" => "ssh",
            "last_health_check_at" => now(),
            "capabilities" => array_fill_keys(
                ["ssh", "sftp", "symlink", "git", "timeout"],
                ["status" => "available"],
            ),
        ]);
        $p = Project::create([
            "name" => "Site",
            "server_id" => $s->id,
            "repository_url" => "https://github.com/example/site",
            "branch" => "main",
            "project_type" => "static",
            "remote_path" => "/home/demo/apps/site",
            "health_check_url" => "https://example.com/health",
            "settings" => ["restart" => "none"],
        ]);
        $d = $p
            ->deployments()
            ->create(["triggered_by" => $u->id, "branch" => "main"]);
        $p->update(["active_deployment_id" => $d->id]);
        return $d;
    }
    private function remote(): void
    {
        $ssh = Mockery::mock(SshServiceInterface::class);
        $ssh->shouldReceive("connect", "upload")->andReturnNull();
        $ssh->shouldReceive("run")->andReturn("SECRET-NEVER-LOG");
        $ssh->shouldReceive("exists")->andReturn(true);
        $this->app->instance(SshServiceInterface::class, $ssh);
        $git = Mockery::mock(GitHubService::class);
        $git->shouldReceive("commit")->andReturn(str_repeat("a", 40));
        $this->app->instance(GitHubService::class, $git);
    }
    public function test_failed_health_check_never_reports_success_or_leaks_output(): void
    {
        $d = $this->setupDeployment();
        $this->remote();
        $health = Mockery::mock(HealthCheckEngine::class);
        $health->shouldReceive("check")->once()->andReturn(false);
        $this->app->instance(HealthCheckEngine::class, $health);
        new DeployProjectJob($d->id)->handle(app(DeploymentService::class));
        $d->refresh();
        $this->assertSame("failed", $d->status);
        $this->assertNull($d->project->active_deployment_id);
        $this->assertStringNotContainsString(
            "SECRET-NEVER-LOG",
            $d->log_output,
        );
        $this->assertStringContainsString(
            "Verify HTTPS health check",
            $d->log_output,
        );
    }
    public function test_success_requires_health_and_records_release(): void
    {
        $d = $this->setupDeployment();
        $this->remote();
        $health = Mockery::mock(HealthCheckEngine::class);
        $health->shouldReceive("check")->once()->andReturn(true);
        $this->app->instance(HealthCheckEngine::class, $health);
        new DeployProjectJob($d->id)->handle(app(DeploymentService::class));
        $d->refresh();
        $this->assertSame("success", $d->status);
        $this->assertTrue($d->rollback_available);
        $this->assertSame($d->id, $d->project->current_deployment_id);
    }
    public function test_optional_capability_failures_are_not_fatal(): void
    {
        $d = $this->setupDeployment();
        $api = Mockery::mock(CpanelServiceInterface::class);
        $api->shouldReceive("inspect")->andReturn([
            "status" => "unavailable",
            "message" => "Restricted",
        ]);
        $ssh = Mockery::mock(SshServiceInterface::class);
        $ssh->shouldReceive("connect")->andReturnNull();
        $ssh->shouldReceive("run")->andThrow(
            new \RuntimeException("Missing runtime"),
        );
        $matrix = new CapabilityDetector($api, $ssh)->check(
            $d->project->server,
        );
        $this->assertSame("available", $matrix["ssh"]["status"]);
        $this->assertSame("unavailable", $matrix["node"]["status"]);
        $this->assertSame("online", $d->project->server->fresh()->status);
    }
    public function test_previous_successful_release_can_be_selected(): void
    {
        $d = $this->setupDeployment();
        $d->update([
            "status" => "success",
            "release_path" => $d->project->remote_path . "/releases/" . $d->id,
            "rollback_available" => true,
        ]);
        $this->assertSame(
            $d->id,
            app(RollbackDeploymentService::class)->select($d->project, $d->id)
                ->id,
        );
    }
}
