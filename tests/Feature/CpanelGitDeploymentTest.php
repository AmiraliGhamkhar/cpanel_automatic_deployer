<?php

namespace Tests\Feature;

use App\Models\{Deployment, Project, Server, User};
use App\Services\Deployment\{DeploymentService, RollbackDeploymentService};
use App\Services\HealthCheckResult;
use App\Services\Remote\CpanelUapiService;
use App\Services\Security\PublicHttp;
use App\Jobs\DeployProjectJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Mockery;
use Tests\TestCase;

/**
 * cPanel Git Version Control mode is the fallback for accounts without usable
 * SSH. The panel must state its limits instead of pretending to support
 * releases and rollbacks: cPanel owns the checkout and the file layout.
 */
class CpanelGitDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private function project(bool $apiReachable = true): array
    {
        $user = User::create([
            "name" => "Admin",
            "email" => "admin@example.com",
            "password" => "long-testing-password",
            "is_admin" => true,
        ]);
        $server = Server::create([
            "name" => "API host",
            "hostname" => "host.example.com",
            "cpanel_username" => "demo",
            "cpanel_api_token" => "api-token",
            "connection_mode" => "cpanel_api",
            "last_health_check_at" => now(),
            "capabilities" => [
                "cpanel_git" => [
                    "status" => $apiReachable ? "available" : "unavailable",
                    "message" => $apiReachable
                        ? "Git Version Control UAPI responded."
                        : "Provider response: VersionControl is not permitted for this account.",
                ],
            ],
        ]);
        $project = Project::create([
            "name" => "Repo",
            "server_id" => $server->id,
            "repository_url" => "https://github.com/example/site",
            "branch" => "main",
            "project_type" => "static",
            "deployment_mode" => "cpanel_git",
            "remote_path" => "/home/demo/repositories/site",
            "health_check_url" => "https://example.com/health",
            "settings" => ["restart" => "none"],
        ]);

        return [$project, $user];
    }

    private function uapi(bool $ok = true): void
    {
        $http = Mockery::mock(PublicHttp::class);
        $http->shouldReceive("get")->andReturnUsing(function (
            string $url,
            array $headers = [],
            array $query = [],
        ) use ($ok) {
            $response = Mockery::mock(Response::class);
            if (!$ok) {
                $response->shouldReceive("status")->andReturn(200);
                $response->shouldReceive("successful")->andReturn(true);
                $response->shouldReceive("json")->andReturn([
                    "result" => [
                        "status" => 0,
                        "errors" =>
                            "VersionControl::update is not permitted for this account.",
                    ],
                ]);
                return $response;
            }
            $response->shouldReceive("status")->andReturn(200);
            $response->shouldReceive("successful")->andReturn(true);
            $response->shouldReceive("json")->andReturn([
                "result" => [
                    "status" => 1,
                    "data" => [
                        "deploy_id" => 17,
                        "log_path" => "/home/demo/.cpanel/logs/vc_1_git_deploy.log",
                        "task_id" => "00000000/5f9f2812355a77",
                        "repository_root" => "/home/demo/repositories/site",
                        "secret_extra" => "must not be stored",
                    ],
                ],
            ]);
            return $response;
        });
        $this->app->instance(CpanelUapiService::class, new CpanelUapiService($http));

        $health = Mockery::mock(\App\Services\HealthCheckEngine::class);
        $health->shouldReceive("check")->andReturn(
            HealthCheckResult::pass("HTTPS request returned HTTP 200."),
        );
        $this->app->instance(\App\Services\HealthCheckEngine::class, $health);
    }

    private function run(Deployment $deployment): void
    {
        new DeployProjectJob($deployment->id)->handle(app(DeploymentService::class));
    }

    public function test_cpanel_git_deployment_updates_and_runs_provider_tasks(): void
    {
        [$project, $user] = $this->project();
        $this->uapi();

        $deployment = app(DeploymentService::class)->trigger(
            $project,
            $user,
            "main",
            null,
            true,
        );
        $this->run($deployment);

        $deployment->refresh();
        $this->assertSame("success", $deployment->status);
        $this->assertSame($project->remote_path, $deployment->release_path);
        // There is no release to roll back to in this mode.
        $this->assertFalse($deployment->rollback_available);
        $this->assertNull($project->fresh()->active_deployment_id);
        $this->assertStringContainsString(
            "Deploy task 17 queued by cPanel.",
            (string) $deployment->log_output,
        );
        $this->assertStringNotContainsString(
            "must not be stored",
            (string) $deployment->log_output,
        );
        $this->assertDatabaseHas("audit_logs", [
            "action" => "DEPLOY_PROJECT",
            "result" => "success",
        ]);
    }

    public function test_provider_errors_are_reported_without_faking_success(): void
    {
        [$project, $user] = $this->project();
        $this->uapi(ok: false);

        $deployment = app(DeploymentService::class)->trigger(
            $project,
            $user,
            "main",
            null,
            true,
        );
        $this->run($deployment);

        $deployment->refresh();
        $this->assertSame("failed", $deployment->status);
        $this->assertSame("Update repository from remote", $deployment->failure_step);
        $this->assertStringContainsString(
            "not permitted",
            (string) $deployment->failure_detail,
        );
        $this->assertNull($project->fresh()->active_deployment_id);
    }

    public function test_deployment_is_refused_when_the_provider_does_not_expose_the_api(): void
    {
        [$project, $user] = $this->project(apiReachable: false);
        $this->uapi();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Git Version Control API did not respond/");
        app(DeploymentService::class)->trigger($project, $user, "main", null, true);
    }

    public function test_pinned_commits_and_rollbacks_are_refused_in_cpanel_git_mode(): void
    {
        [$project, $user] = $this->project();
        $this->uapi();

        try {
            app(DeploymentService::class)->trigger(
                $project,
                $user,
                "main",
                str_repeat("a", 40),
                true,
            );
            $this->fail("A pinned commit was accepted for cPanel Git mode.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("pinned commit", $e->getMessage());
        }

        $previous = $project->deployments()->create([
            "triggered_by" => $user->id,
            "branch" => "main",
            "status" => "success",
            "rollback_available" => true,
            "release_path" => $project->remote_path,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/cannot be pinned to a commit/");
        app(RollbackDeploymentService::class)->trigger(
            $project,
            $user,
            $previous->id,
            true,
        );
    }

    public function test_uapi_adapter_rejects_unsupported_endpoints_and_parameters(): void
    {
        $uapi = new CpanelUapiService(Mockery::mock(PublicHttp::class));
        [$project] = $this->project();

        $this->expectException(\InvalidArgumentException::class);
        $uapi->call($project->server, "VersionControl/update; rm -rf /");
    }
}
