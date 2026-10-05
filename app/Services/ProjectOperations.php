<?php

namespace App\Services;

use App\Models\{Project, User};
use App\Services\Security\Input;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Project-scoped operations that are not deployments: environment changes and
 * the maintenance operations (backup, restart, database import).
 *
 * Every entry point authorises the user, requires explicit confirmation for
 * destructive or secret-bearing changes, takes the project lock and writes an
 * audit record.
 */
class ProjectOperations
{
    public function environment(
        Project $project,
        User $user,
        string $key,
        ?string $value,
        bool $delete,
        bool $confirmed,
    ): void {
        Gate::forUser($user)->authorize("operate", $project);
        if (!$confirmed) {
            throw new RuntimeException("Confirmation required.");
        }
        Input::env([$key => $value ?? ""]);
        app(Operations\StaleOperationReaper::class)->reap($project);

        DB::transaction(function () use ($project, $user, $key, $value, $delete) {
            \App\Models\Server::lockForUpdate()->findOrFail($project->server_id);
            $p = Project::lockForUpdate()->findOrFail($project->id);
            if ($p->active_deployment_id !== null) {
                throw new RuntimeException("Project is busy.");
            }
            if ($p->project_type === "static" && !$delete) {
                throw new RuntimeException(
                    "Static sites cannot store deployment secrets. Use reviewed public build configuration in the repository.",
                );
            }
            $env = $p->environment_config ?? [];
            if ($delete) {
                unset($env[$key]);
            } else {
                $env[$key] = $value ?? "";
            }
            Input::env($env);
            $p->update(["environment_config" => $env]);
            Audit::record(
                "UPDATE_ENVIRONMENT",
                "success",
                $p->id,
                $p->server_id,
                $user->id,
            );
        });
    }

    public function maintenance(
        Project $project,
        User $user,
        string $operation,
        bool $confirmed,
        string $backupType = "files",
    ): void {
        Gate::forUser($user)->authorize("operate", $project);
        if (!$confirmed || !in_array($operation, ["backup", "restart"], true)) {
            throw new RuntimeException(
                "Confirmed supported operation required.",
            );
        }
        if (!in_array($backupType, ["files", "database"], true)) {
            throw new RuntimeException("Unsupported backup type.");
        }
        if (
            $operation === "backup" &&
            $backupType === "database" &&
            !Backup\DatabaseCredentials::fromEnvironment(
                $project->environment_config ?? [],
            )
        ) {
            throw new RuntimeException(
                "Set DATABASE_URL or DB_DATABASE/DB_USERNAME/DB_PASSWORD before requesting a database backup.",
            );
        }
        if (
            $operation === "restart" &&
            $project->setting("restart", "none") !== "passenger"
        ) {
            throw new RuntimeException(
                "No supported application restart mechanism configured.",
            );
        }

        app(Operations\StaleOperationReaper::class)->reap($project);

        $this->claimMaintenance(
            $project,
            $user,
            function (string $token) use ($project, $user, $operation, $backupType) {
                if ($operation === "backup") {
                    $backup = $project->backups()->create(["type" => $backupType]);
                    \App\Jobs\BackupProjectJob::dispatch(
                        $backup->id,
                        $user->id,
                        $token,
                    );
                } else {
                    \App\Jobs\RestartProjectJob::dispatch(
                        $project->id,
                        $user->id,
                        $token,
                    );
                }
                Audit::record(
                    strtoupper($operation) . "_PROJECT",
                    "queued",
                    $project->id,
                    $project->server_id,
                    $user->id,
                );
            },
        );
    }

    /**
     * Take the project maintenance lock and hand the one-time token to the
     * caller's dispatcher. The lock is only ever set inside this transaction,
     * so a job can never observe an uncommitted claim.
     *
     * @param callable(string):void $dispatch
     */
    public function claimMaintenance(
        Project $project,
        User $user,
        callable $dispatch,
    ): void {
        Gate::forUser($user)->authorize("operate", $project);

        DB::transaction(function () use ($project, $dispatch) {
            \App\Models\Server::lockForUpdate()->findOrFail($project->server_id);
            $p = Project::lockForUpdate()->findOrFail($project->id);
            if (
                $p->active_deployment_id !== null ||
                !$p->enabled ||
                !$p->server->enabled ||
                !$p->current_deployment_id
            ) {
                throw new RuntimeException(
                    "Project is busy, disabled or has no active release.",
                );
            }
            $token = (string) Str::uuid();
            $p->update([
                "active_deployment_id" => 0,
                "active_operation_token" => $token,
            ]);
            $dispatch($token);
        });
    }
}
