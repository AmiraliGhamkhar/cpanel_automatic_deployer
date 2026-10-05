<?php

namespace App\Services\Deployment;

use App\Models\{Deployment, Project, User};
use App\Services\{Audit, HealthCheckEngine};
use App\Services\Remote\CpanelUapiService;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * cPanel Git™ Version Control deployment mode.
 *
 * For accounts that expose the cPanel API but no usable SSH. cPanel itself owns
 * the checkout and the file layout: the repository is pulled with
 * VersionControl::update and its `.cpanel.yml` tasks are executed with
 * VersionControlDeployment::create.
 *
 * Consequences, which the panel states instead of hiding:
 *  - the deployed layout is defined by the repository's `.cpanel.yml`, not by
 *    the panel, so there is no release directory to roll back to;
 *  - a commit cannot be pinned, so only the latest branch commit is deployed;
 *  - log output is whatever the provider returns.
 */
class CpanelGitDeploymentService
{
    public function __construct(
        private CpanelUapiService $uapi,
        private HealthCheckEngine $health,
        private DeploymentLog $log,
    ) {}

    public function supports(Project $project): bool
    {
        return $project->deployment_mode === "cpanel_git";
    }

    public function execute(Deployment $deployment): void
    {
        $project = $deployment->project;
        $user = User::findOrFail($deployment->triggered_by);
        Gate::forUser($user)->authorize("deploy", $project);

        $repositoryRoot = $project->remote_path;

        $this->log->step($deployment, "Update repository from remote", function () use (
            $project,
            $repositoryRoot,
            $deployment,
        ) {
            $result = $this->uapi->call(
                $project->server,
                "VersionControl/update",
                [
                    "repository_root" => $repositoryRoot,
                    "branch" => $deployment->branch,
                ],
            );
            $this->assertAvailable($result, "git_pull");
        });

        $this->log->step($deployment, "Run cPanel deployment tasks", function () use (
            $project,
            $repositoryRoot,
            $deployment,
        ) {
            $result = $this->uapi->call(
                $project->server,
                "VersionControlDeployment/create",
                ["repository_root" => $repositoryRoot],
                ["deploy_id", "log_path", "repository_root"],
            );
            $this->assertAvailable($result, "git_deploy");
            $deployment->update(["release_path" => $repositoryRoot]);
            return isset($result["data"]["deploy_id"])
                ? "Deploy task " . $result["data"]["deploy_id"] . " queued by cPanel."
                : "Deploy task queued by cPanel.";
        });

        // cPanel's deployment tasks run asynchronously and the panel cannot read
        // their result, so the configured health check is the only acceptance
        // signal. A failure marks the deployment failed with an explicit reason.
        $this->log->step($deployment, "Verify configured health check", function () use (
            $project,
            $deployment,
        ) {
            $result = $this->health->check($project);
            Project::whereKey($project->id)->update([
                "health_checked_at" => now(),
                "health_check_message" => mb_substr($result->message, 0, 500),
            ]);
            if (!$result->passed) {
                throw new RuntimeException(
                    "Health check failed: " .
                        $result->message .
                        " cPanel deploy tasks run asynchronously; check the deploy log in cPanel's Git interface.",
                );
            }
        });

        $deployment->update([
            "finished_at" => now(),
            "duration" => $deployment->started_at
                ? (int) $deployment->started_at->diffInSeconds(now())
                : 0,
            "rollback_available" => false,
        ]);
        $deployment->transition("success");
        $project->update([
            "active_deployment_id" => null,
            "status" => "running",
        ]);
        Audit::record(
            "DEPLOY_PROJECT",
            "success",
            $project->id,
            $project->server_id,
            $deployment->triggered_by,
        );
    }

    /** Turn a UAPI failure into a first-party, provider-aware message. */
    private function assertAvailable(array $result, string $what): void
    {
        if ($result["status"] === "available") {
            return;
        }
        Audit::record("CPANEL_" . strtoupper($what), $result["status"]);
        throw new RuntimeException(
            "cPanel API call failed (" .
                $what .
                "): " .
                ($result["message"] ?? "unknown provider error"),
        );
    }
}
