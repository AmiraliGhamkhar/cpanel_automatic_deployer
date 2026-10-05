<?php

namespace App\Services\Backup;

use App\Models\{Backup, Deployment, Project, User};
use App\Services\Audit;
use App\Services\Deployment\DeploymentService;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Restores are always explicit, confirmed and recorded.
 *
 * A file restore is modelled as a deployment of kind `restore`: it reuses the
 * same release verification, activation, restart, health check and rollback
 * machinery, so a bad restore can itself be rolled back. A database restore
 * changes data irreversibly and is therefore a separate, database-only
 * operation that never happens as part of a release rollback.
 */
class RestoreService
{
    public function __construct(private DeploymentService $deployments) {}

    /** File archive -> new release deployed through the normal pipeline. */
    public function asDeployment(
        Project $project,
        User $user,
        Backup $backup,
        bool $confirmed,
    ): Deployment {
        Gate::forUser($user)->authorize("deploy", $project);
        if (!$confirmed) {
            throw new RuntimeException(
                "Restoring a backup requires explicit confirmation.",
            );
        }
        if ($backup->type !== "files" || $backup->status !== "success") {
            throw new RuntimeException(
                "Only a successful file backup can be restored as a release.",
            );
        }
        if ($backup->project_id !== $project->id) {
            throw new RuntimeException("Backup belongs to another project.");
        }
        if ($project->active_deployment_id !== null) {
            throw new RuntimeException("Project is busy.");
        }

        return $this->deployments->queue(
            $project,
            $user,
            "restore",
            $backup->metadata["branch"] ?? $project->branch,
            null,
            null,
            $backup->id,
        );
    }

    /** Database dump -> in-place import, no release change. */
    public function database(
        Project $project,
        User $user,
        Backup $backup,
        bool $confirmed,
        string $operationToken,
    ): void {
        Gate::forUser($user)->authorize("operate", $project);
        if (!$confirmed) {
            throw new RuntimeException(
                "Importing a database dump overwrites the live database and requires explicit confirmation.",
            );
        }
        if ($backup->type !== "database" || $backup->status !== "success") {
            throw new RuntimeException("Only a successful database dump can be imported.");
        }
        if ($backup->project_id !== $project->id) {
            throw new RuntimeException("Backup belongs to another project.");
        }
        if ($project->current_deployment_id === null) {
            throw new RuntimeException("Deploy the project before importing a database dump.");
        }
        \App\Jobs\RestoreDatabaseJob::dispatch(
            $backup->id,
            $user->id,
            $operationToken,
        );
    }

    /** Delete a managed archive on the host and its record. */
    public function delete(
        Project $project,
        User $user,
        Backup $backup,
        bool $confirmed,
        \App\Services\Remote\SshServiceInterface $ssh,
    ): void {
        Gate::forUser($user)->authorize("operate", $project);
        if (!$confirmed) {
            throw new RuntimeException("Deleting a backup requires confirmation.");
        }
        if ($backup->project_id !== $project->id) {
            throw new RuntimeException("Backup belongs to another project.");
        }
        if ($backup->status === "success" && $backup->path) {
            $ssh->connect($project->server);
            $ssh->run(
                \App\Services\Remote\Command::deleteArchive(
                    $project->remote_path,
                    $backup->id,
                    $backup->type,
                ),
            );
        }
        $backup->delete();
        Audit::record(
            "DELETE_BACKUP",
            "success",
            $project->id,
            $project->server_id,
            $user->id,
        );
    }
}
