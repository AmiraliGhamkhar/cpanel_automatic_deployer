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
    public function handle(
        HealthCheckEngine $health,
        \App\Services\Remote\SshServiceInterface $ssh,
    ): void {
        $p = Project::findOrFail($this->projectId);
        Gate::forUser(User::findOrFail($this->userId))->authorize(
            "operate",
            $p,
        );
        $result = $health->check($p, $this->sshSession($p, $ssh));
        Project::whereKey($p->id)
            ->whereNull("active_deployment_id")
            ->where("current_deployment_id", $p->current_deployment_id)
            ->where("health_check_url", $p->health_check_url)
            ->update([
                "status" => $result->passed ? "running" : "failed",
                "health_checked_at" => now(),
                "health_check_message" => $result->message,
            ]);
        Audit::record(
            "HEALTH_CHECK",
            $result->passed ? "success" : "failed",
            $p->id,
            $p->server_id,
            $this->userId,
        );
    }

    /**
     * Only connect when the configured strategy needs SSH; an HTTP or TCP
     * probe must not fail because SSH is unavailable on an API-only host.
     */
    private function sshSession(
        Project $p,
        \App\Services\Remote\SshServiceInterface $ssh,
    ): ?\App\Services\Remote\SshServiceInterface {
        if (($p->settings["health_type"] ?? "http") !== "process") {
            return null;
        }
        try {
            $ssh->connect($p->server);
            return $ssh;
        } catch (\Throwable) {
            return null;
        }
    }
}
