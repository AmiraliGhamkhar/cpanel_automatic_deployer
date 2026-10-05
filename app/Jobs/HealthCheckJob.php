<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use App\Models\{Project, User};
use App\Services\{HealthCheckEngine, Audit};
class HealthCheckJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 100;
    public function __construct(public int $projectId, public int $userId) {}
    public function handle(HealthCheckEngine $health): void
    {
        $p = Project::findOrFail($this->projectId);
        Gate::forUser(User::findOrFail($this->userId))->authorize(
            "operate",
            $p,
        );
        $ok = $health->check($p->health_check_url);
        Project::whereKey($p->id)
            ->whereNull("active_deployment_id")
            ->where("current_deployment_id", $p->current_deployment_id)
            ->where("health_check_url", $p->health_check_url)
            ->update(["status" => $ok ? "running" : "failed"]);
        Audit::record(
            "HEALTH_CHECK",
            $ok ? "success" : "failed",
            $p->id,
            $p->server_id,
            $this->userId,
        );
    }
}
