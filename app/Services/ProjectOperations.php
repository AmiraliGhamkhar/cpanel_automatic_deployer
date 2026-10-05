<?php
namespace App\Services;
use App\Models\{Project, User};
use Illuminate\Support\Facades\{DB, Gate};
use App\Services\Security\Input;
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
            throw new \RuntimeException("Confirmation required.");
        }
        Input::env([$key => $value ?? ""]);
        app(\App\Services\Operations\StaleOperationReaper::class)->reap($project);
        DB::transaction(function () use (
            $project,
            $user,
            $key,
            $value,
            $delete,
        ) {
            \App\Models\Server::lockForUpdate()->findOrFail(
                $project->server_id,
            );
            $p = Project::lockForUpdate()->findOrFail($project->id);
            if ($p->active_deployment_id !== null) {
                throw new \RuntimeException("Project is busy.");
            }
            if ($p->project_type === "static" && !$delete) {
                throw new \RuntimeException(
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
    ): void {
        Gate::forUser($user)->authorize("operate", $project);
        if (!$confirmed || !in_array($operation, ["backup", "restart"], true)) {
            throw new \RuntimeException(
                "Confirmed supported operation required.",
            );
        }
        app(\App\Services\Operations\StaleOperationReaper::class)->reap($project);
        DB::transaction(function () use ($project, $user, $operation) {
            \App\Models\Server::lockForUpdate()->findOrFail(
                $project->server_id,
            );
            $p = Project::lockForUpdate()->findOrFail($project->id);
            if (
                $p->active_deployment_id !== null ||
                !$p->enabled ||
                !$p->server->enabled ||
                !$p->current_deployment_id
            ) {
                throw new \RuntimeException(
                    "Project is busy, disabled or has no active release.",
                );
            }
            if (
                $operation === "restart" &&
                ($p->settings["restart"] ?? "none") !== "passenger"
            ) {
                throw new \RuntimeException(
                    "No supported application restart mechanism configured.",
                );
            }
            $token = (string) \Illuminate\Support\Str::uuid();
            $p->update([
                "active_deployment_id" => 0,
                "active_operation_token" => $token,
            ]);
            if ($operation === "backup") {
                $b = $p->backups()->create(["type" => "files"]);
                \App\Jobs\BackupProjectJob::dispatch($b->id, $user->id, $token);
            } else {
                \App\Jobs\RestartProjectJob::dispatch(
                    $p->id,
                    $user->id,
                    $token,
                );
            }
            Audit::record(
                strtoupper($operation) . "_PROJECT",
                "queued",
                $p->id,
                $p->server_id,
                $user->id,
            );
        });
    }
}
