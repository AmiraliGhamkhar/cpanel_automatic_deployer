<?php

namespace Tests\Feature;

use App\Models\{Backup, Deployment, Project, Server, User};
use App\Services\Operations\StaleOperationReaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A queue worker that dies mid-deployment must not lock a project forever.
 */
class StaleOperationTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user, ?int $ageSeconds = null): Project
    {
        $server = Server::create([
            "name" => "Target",
            "hostname" => "host.example.com",
            "cpanel_username" => "demo",
            "ssh_username" => "demo",
            "connection_mode" => "ssh",
            "capabilities" => array_fill_keys(
                ["ssh", "sftp", "symlink", "git", "timeout"],
                ["status" => "available"],
            ),
        ]);
        $project = Project::create([
            "name" => "Site",
            "server_id" => $server->id,
            "repository_url" => "https://github.com/example/site",
            "branch" => "main",
            "project_type" => "static",
            "remote_path" => "/home/demo/apps/site",
            "health_check_url" => "https://example.com/health",
            "settings" => ["restart" => "none"],
        ]);
        $deployment = $project->deployments()->create([
            "triggered_by" => $user->id,
            "branch" => "main",
            "status" => "running",
            "started_at" => now(),
        ]);
        $project->update(["active_deployment_id" => $deployment->id]);
        if ($ageSeconds !== null) {
            // Fresh timestamps bypass Eloquent so they are not overwritten.
            Project::whereKey($project->id)->update([
                "updated_at" => now()->subSeconds($ageSeconds),
            ]);
        }
        return $project->fresh();
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

    public function test_recent_running_operations_are_never_reaped(): void
    {
        $project = $this->project($this->admin());
        $released = app(StaleOperationReaper::class)->reap();

        $this->assertSame([], $released);
        $this->assertNotNull($project->fresh()->active_deployment_id);
        $this->assertSame("running", Deployment::find($project->active_deployment_id)->status);
    }

    public function test_abandoned_deployment_is_failed_and_the_lock_released(): void
    {
        $project = $this->project($this->admin(), 3600);
        $released = app(StaleOperationReaper::class)->reap();

        $this->assertCount(1, $released);
        $project->refresh();
        $this->assertNull($project->active_deployment_id);
        $deployment = Deployment::find($project->deployments()->value("id"));
        $this->assertSame("failed", $deployment->status);
        $this->assertDatabaseHas("audit_logs", [
            "action" => "REAP_STALE_OPERATION",
            "result" => "deployment_failed",
        ]);
    }

    public function test_abandoned_maintenance_operation_is_released(): void
    {
        $project = $this->project($this->admin());
        $backup = $project->backups()->create(["type" => "files"]);
        $project->update([
            "active_deployment_id" => 0,
            "active_operation_token" => (string) \Illuminate\Support\Str::uuid(),
        ]);
        Project::whereKey($project->id)->update([
            "updated_at" => now()->subSeconds(3600),
        ]);

        app(StaleOperationReaper::class)->reap();

        $project->refresh();
        $this->assertNull($project->active_deployment_id);
        $this->assertNull($project->active_operation_token);
        $this->assertSame("failed", $backup->fresh()->status);
    }

    public function test_force_releases_an_operation_that_is_not_stale_yet(): void
    {
        $project = $this->project($this->admin());
        app(StaleOperationReaper::class)->reap($project, true);

        $this->assertNull($project->fresh()->active_deployment_id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $project = $this->project($this->admin(), 3600);
        $released = app(StaleOperationReaper::class)->reap(null, false, null, true);

        $this->assertCount(1, $released);
        $this->assertNotNull($project->fresh()->active_deployment_id);
    }
}
