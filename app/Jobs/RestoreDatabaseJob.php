<?php

namespace App\Jobs;

use App\Models\{Backup, Project, User};
use App\Services\Backup\BackupTypeRegistry;
use App\Services\{Audit, HealthCheckEngine};
use App\Services\Remote\SshServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Gate;

/**
 * Imports a database dump. This overwrites live data, so it runs only from an
 * explicitly confirmed request, holds the project maintenance lock and is
 * always recorded in the audit log.
 */
class RestoreDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 700;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $backupId,
        public int $userId,
        public string $operationToken,
    ) {}

    public function middleware(): array
    {
        return [
            new WithoutOverlapping("maintenance-" . $this->operationToken)
                ->dontRelease()
                ->expireAfter(900),
        ];
    }

    public function handle(
        BackupTypeRegistry $registry,
        SshServiceInterface $ssh,
        HealthCheckEngine $health,
    ): void {
        $backup = Backup::findOrFail($this->backupId);
        $project = $backup->project;
        if (
            $project->active_deployment_id !== 0 ||
            $project->active_operation_token !== $this->operationToken
        ) {
            return;
        }

        $ok = false;
        try {
            Gate::forUser(User::findOrFail($this->userId))->authorize(
                "operate",
                $project,
            );
            $ssh->connect($project->server);
            $registry->for("database")->restore($project, $backup, $ssh);
            $ok = $health->check($project, $ssh)->passed;
        } catch (\Throwable) {
            $ok = false;
        } finally {
            Project::whereKey($project->id)
                ->where("active_deployment_id", 0)
                ->where("active_operation_token", $this->operationToken)
                ->update([
                    "active_deployment_id" => null,
                    "active_operation_token" => null,
                    "status" => $ok ? "running" : "failed",
                    "health_checked_at" => now(),
                    "health_check_message" => $ok
                        ? "Database import completed and the health check passed."
                        : "Database import failed or the health check did not pass.",
                ]);
            Audit::record(
                "RESTORE_BACKUP",
                $ok ? "success" : "failed",
                $project->id,
                $project->server_id,
                $this->userId,
            );
        }
    }

    public function failed(?\Throwable $e): void
    {
        $backup = Backup::find($this->backupId);
        if ($backup) {
            Project::whereKey($backup->project_id)
                ->where("active_deployment_id", 0)
                ->where("active_operation_token", $this->operationToken)
                ->update([
                    "active_deployment_id" => null,
                    "active_operation_token" => null,
                    "status" => "failed",
                ]);
        }
    }
}
