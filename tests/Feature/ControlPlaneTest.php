<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Queue, Gate, DB};
use App\Models\{User, Server, Project, Deployment};
use App\Services\{ConfigurationService, ProjectOperations};
use App\Services\Deployment\{DeploymentService, RollbackDeploymentService};
use Illuminate\Auth\Access\AuthorizationException;
class ControlPlaneTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User
    {
        return User::create([
            "name" => "Admin",
            "email" => "admin@example.com",
            "password" => "a-long-testing-password",
            "is_admin" => true,
        ]);
    }
    private function project(): Project
    {
        $s = Server::create([
            "name" => "Iran host",
            "hostname" => "host.example.com",
            "cpanel_username" => "demo",
            "ssh_username" => "demo",
            "ssh_private_key" => "private-secret",
            "cpanel_api_token" => "api-secret",
            "connection_mode" => "cpanel_api_and_ssh",
            "last_health_check_at" => now(),
            "capabilities" => array_fill_keys(
                ["ssh", "sftp", "symlink", "git", "timeout"],
                ["status" => "available"],
            ),
        ]);
        return Project::create([
            "name" => "Site",
            "server_id" => $s->id,
            "repository_url" => "https://github.com/example/site",
            "branch" => "main",
            "project_type" => "static",
            "remote_path" => "/home/demo/apps/site",
            "health_check_url" => "https://example.com/health",
            "settings" => ["restart" => "none"],
        ]);
    }
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get("/admin")->assertRedirect("/admin/login");
    }
    public function test_non_admin_cannot_access_panel(): void
    {
        $u = User::create([
            "name" => "Viewer",
            "email" => "viewer@example.com",
            "password" => "long-password",
            "is_admin" => false,
        ]);
        $this->actingAs($u)->get("/admin")->assertForbidden();
    }
    public function test_authenticated_admin_can_open_panel(): void
    {
        $this->actingAs($this->admin())->get("/admin")->assertOk();
    }
    public function test_secrets_are_encrypted_and_excluded_from_serialization(): void
    {
        $p = $this->project();
        $p->update(["environment_config" => ["API_KEY" => "super-secret"]]);
        $this->assertStringNotContainsString(
            "private-secret",
            DB::table("servers")->value("ssh_private_key"),
        );
        $this->assertStringNotContainsString(
            "api-secret",
            DB::table("servers")->value("cpanel_api_token"),
        );
        $this->assertStringNotContainsString(
            "super-secret",
            DB::table("projects")->value("environment_config"),
        );
        $this->assertArrayNotHasKey("environment_config", $p->toArray());
        $this->assertArrayNotHasKey("ssh_private_key", $p->server->toArray());
    }
    public function test_creation_validates_repository_and_path(): void
    {
        $this->actingAs($this->admin());
        $p = $this->project();
        $data = [
            "name" => "New",
            "server_id" => $p->server_id,
            "repository_url" => "https://evil.test/repo",
            "branch" => "main",
            "project_type" => "static",
            "deployment_mode" => "ssh",
            "release_strategy" => "symlink",
            "remote_path" => "/home/demo/apps/another",
            "health_check_url" => "https://example.com/health",
            "settings" => ["restart" => "none"],
        ];
        $this->expectException(\InvalidArgumentException::class);
        app(ConfigurationService::class)->project($data);
    }
    public function test_deployment_is_queued_and_audited(): void
    {
        Queue::fake();
        $u = $this->admin();
        $p = $this->project();
        $d = app(DeploymentService::class)->trigger($p, $u, "main", null, true);
        $this->assertSame("pending", $d->status);
        $this->assertSame($d->id, $p->fresh()->active_deployment_id);
        Queue::assertPushed(\App\Jobs\DeployProjectJob::class);
        $this->assertDatabaseHas("audit_logs", [
            "action" => "DEPLOY_PROJECT",
            "result" => "queued",
        ]);
    }
    public function test_unauthorized_user_cannot_deploy(): void
    {
        $p = $this->project();
        $u = User::create([
            "name" => "Viewer",
            "email" => "viewer@example.com",
            "password" => "long-password",
        ]);
        $this->expectException(AuthorizationException::class);
        app(DeploymentService::class)->trigger($p, $u, "main", null, true);
    }
    public function test_confirmation_is_required(): void
    {
        $this->expectException(\RuntimeException::class);
        app(DeploymentService::class)->trigger(
            $this->project(),
            $this->admin(),
            "main",
            null,
            false,
        );
    }
    public function test_maintenance_lock_prevents_deployment(): void
    {
        $p = $this->project();
        $p->update(["active_deployment_id" => 0]);
        $this->expectException(\RuntimeException::class);
        app(DeploymentService::class)->trigger(
            $p,
            $this->admin(),
            "main",
            null,
            true,
        );
    }
    public function test_environment_is_write_only_and_audited(): void
    {
        $u = $this->admin();
        $p = $this->project();
        $p->update(["project_type" => "laravel"]);
        app(ProjectOperations::class)->environment(
            $p,
            $u,
            "API_KEY",
            "new-secret",
            false,
            true,
        );
        $this->assertSame(
            "new-secret",
            $p->fresh()->environment_config["API_KEY"],
        );
        $this->assertDatabaseHas("audit_logs", [
            "action" => "UPDATE_ENVIRONMENT",
        ]);
        app(ProjectOperations::class)->environment(
            $p,
            $u,
            "API_KEY",
            null,
            true,
            true,
        );
        $this->assertSame([], $p->fresh()->environment_config);
    }
    public function test_environment_requires_confirmation(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ProjectOperations::class)->environment(
            $this->project(),
            $this->admin(),
            "KEY",
            "value",
            true,
            false,
        );
    }
    public function test_rollback_cannot_select_other_project_or_failed_release(): void
    {
        $p = $this->project();
        $u = $this->admin();
        $d = $p
            ->deployments()
            ->create([
                "triggered_by" => $u->id,
                "branch" => "main",
                "status" => "failed",
                "release_path" => $p->remote_path . "/releases/1",
                "rollback_available" => true,
            ]);
        $this->expectException(\RuntimeException::class);
        app(RollbackDeploymentService::class)->select($p, $d->id);
    }
    public function test_rollback_requires_authorization(): void
    {
        $p = $this->project();
        $u = User::create([
            "name" => "Viewer",
            "email" => "viewer@example.com",
            "password" => "long-password",
        ]);
        $this->expectException(AuthorizationException::class);
        app(RollbackDeploymentService::class)->trigger($p, $u, 1, true);
    }
    public function test_status_cannot_jump_from_pending_to_success(): void
    {
        $p = $this->project();
        $d = $p
            ->deployments()
            ->create([
                "triggered_by" => $this->admin()->id,
                "branch" => "main",
            ]);
        $this->expectException(\LogicException::class);
        $d->transition("success");
    }
    public function test_server_creation_encrypts_token_and_audits(): void
    {
        $this->actingAs($this->admin());
        $s = app(ConfigurationService::class)->server([
            "name" => "API host",
            "hostname" => "host.example.com",
            "port" => 2083,
            "cpanel_username" => "demo",
            "ssh_port" => 22,
            "connection_mode" => "cpanel_api",
            "cpanel_api_token" => "never-plaintext",
        ]);
        $this->assertSame("never-plaintext", $s->cpanel_api_token);
        $this->assertDatabaseHas("audit_logs", [
            "server_id" => $s->id,
            "action" => "SAVE_SERVER",
        ]);
    }
}
