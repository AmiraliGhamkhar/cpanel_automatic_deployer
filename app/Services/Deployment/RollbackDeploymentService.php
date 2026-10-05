<?php
namespace App\Services\Deployment;
use App\Models\{Project, Deployment, User};
use Illuminate\Support\Facades\Gate;
class RollbackDeploymentService
{
    public function select(Project $p, int $id): Deployment
    {
        $d = $p
            ->deployments()
            ->whereKey($id)
            ->whereIn("status", ["success", "rolled_back"])
            ->where("rollback_available", true)
            ->first();
        if (
            !$d ||
            !in_array($p->release_strategy, ["symlink", "in_place"], true) ||
            !preg_match(
                "~\A" .
                    preg_quote($p->remote_path, "~") .
                    "/releases/[0-9]+\z~D",
                $d->release_path ?? "",
            ) ||
            $p->current_deployment_id === $id
        ) {
            throw new \RuntimeException(
                "Select a different verified successful release from this project.",
            );
        }
        return $d;
    }
    public function trigger(
        Project $p,
        User $u,
        int $id,
        bool $confirmed,
    ): Deployment {
        Gate::forUser($u)->authorize("deploy", $p);
        if (!$confirmed) {
            throw new \RuntimeException(
                "Rollback confirmation is required. Databases are not rolled back.",
            );
        }
        $target = $this->select($p, $id);
        return app(DeploymentService::class)->queue(
            $p,
            $u,
            "rollback",
            $target->branch,
            $target->commit_hash,
            $target,
        );
    }
}
