<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use App\Models\{Project, User};
use App\Services\{Audit, HealthCheckEngine};
use App\Services\Deployment\DeploymentService;
use App\Services\Remote\{SshServiceInterface, Command};
class RestartProjectJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 700;
    public bool $failOnTimeout = true;
    public function __construct(
        public int $projectId,
        public int $userId,
        public string $operationToken,
    ) {}
    public function middleware(): array
    {
        return [
            new \Illuminate\Queue\Middleware\WithoutOverlapping(
                "maintenance-" . $this->operationToken,
            )
                ->dontRelease()
                ->expireAfter(900),
        ];
    }
    public function handle(
        SshServiceInterface $ssh,
        HealthCheckEngine $health,
    ): void {
        $p = Project::findOrFail($this->projectId);
        if (
            $p->active_deployment_id !== 0 ||
            $p->active_operation_token !== $this->operationToken
        ) {
            return;
        }
        $ok = false;
        try {
            Gate::forUser(User::findOrFail($this->userId))->authorize(
                "operate",
                $p,
            );
            if (!$p->enabled || !$p->server->enabled) {
                throw new \RuntimeException("Disabled project");
            }
            $ssh->connect($p->server);
            $ssh->run(
                Command::initialize(
                    $p->remote_path,
                    $p->id,
                    $p->server->ssh_username,
                ),
            );
            $ssh->run(Command::prepare($p->remote_path, $p->id));
            $ssh->upload(
                $p->remote_path . "/shared/.env",
                \App\Services\EnvironmentFile::encode(
                    $p->environment_config ?? [],
                ),
            );
            $ssh->run(
                Command::install($p->remote_path . "/current", "passenger"),
            );
            $ok = $health->check($p->health_check_url);
        } catch (\Throwable) {
        } finally {
            Project::whereKey($p->id)
                ->where("active_deployment_id", 0)
                ->where("active_operation_token", $this->operationToken)
                ->update([
                    "active_deployment_id" => null,
                    "active_operation_token" => null,
                    "status" => $ok ? "running" : "failed",
                ]);
            Audit::record(
                "RESTART_PROJECT",
                $ok ? "success" : "failed",
                $p->id,
                $p->server_id,
                $this->userId,
            );
        }
    }
    public function failed(?\Throwable $e): void
    {
        Project::whereKey($this->projectId)
            ->where("active_deployment_id", 0)
            ->where("active_operation_token", $this->operationToken)
            ->update([
                "active_deployment_id" => null,
                "active_operation_token" => null,
                "status" => "failed",
            ]);
    }
}
