<?php

namespace App\Services\Operations;

use App\Models\{Backup, Deployment, Project};
use App\Services\{Audit, Deployment\DeploymentService};
use Illuminate\Support\Facades\DB;

/**
 * Releases project locks that belong to dead operations.
 *
 * A queue worker that is killed mid-deployment (provider kill, container
 * restart, exhausted timeout without the failure callback firing) leaves
 * active_deployment_id set and blocks the project forever. This service
 * detects operations that have exceeded the configured threshold and moves
 * them to a terminal state so the operator can deploy again.
 *
 * Age is the only automatic signal. `force` exists for the explicit,
 * confirmed "release stuck operation" action in the UI.
 */
class StaleOperationReaper
{
    public function __construct(private DeploymentService $deployments) {}

    public function thresholdSeconds(): int
    {
        return max(600, (int) config("control.stale_operation_after", 2700));
    }

    /**
     * @return list<string> human-readable descriptions of what was released
     */
    public function reap(
        ?Project $only = null,
        bool $force = false,
        ?int $userId = null,
        bool $dryRun = false,
    ): array {
        $released = [];
        $query = Project::query()->whereNotNull("active_deployment_id");
        if ($only) {
            $query->whereKey($only->id);
        }

        foreach ($query->get() as $project) {
            $age = $project->updated_at ?? now();
            $isStale = $force || $age->lt(now()->subSeconds($this->thresholdSeconds()));
            if (!$isStale) {
                continue;
            }
            if ($dryRun) {
                $released[] = "would release the active operation on " . $project->name . ".";
                continue;
            }
            $released[] = $this->release($project, $userId);
        }

        return array_values(array_filter($released));
    }

    private function release(Project $project, ?int $userId): ?string
    {
        return DB::transaction(function () use ($project, $userId) {
            $p = Project::lockForUpdate()->find($project->id);
            if (!$p || $p->active_deployment_id === null) {
                return null;
            }

            // A deployment is in flight: fail it through the normal path so the
            // deployment history, audit trail and lock stay consistent.
            if ($p->active_deployment_id > 0) {
                $d = Deployment::find($p->active_deployment_id);
                if ($d && in_array($d->status, ["pending", "running"], true)) {
                    $this->deployments->fail($d->id);
                    Audit::record("REAP_STALE_OPERATION", "deployment_failed", $p->id, $p->server_id, $userId);
                    return "Deployment #" . $d->id . " on " . $p->name . " marked failed and unlocked.";
                }
                $p->update(["active_deployment_id" => null, "active_operation_token" => null]);
                Audit::record("REAP_STALE_OPERATION", "lock_cleared", $p->id, $p->server_id, $userId);
                return "Stale lock on " . $p->name . " cleared.";
            }

            // Maintenance sentinel (backup or restart).
            $token = $p->active_operation_token;
            Backup::where("project_id", $p->id)
                ->whereIn("status", ["pending", "running"])
                ->update(["status" => "failed"]);
            $p->update(["active_deployment_id" => null, "active_operation_token" => null]);
            Audit::record("REAP_STALE_OPERATION", "maintenance_cleared", $p->id, $p->server_id, $userId);
            return "Stale maintenance operation on " . $p->name .
                ($token ? " (" . substr($token, 0, 8) . "…)" : "") . " cleared.";
        });
    }
}
