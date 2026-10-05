<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\{Project, User, Backup};
use App\Services\{BackupManager, Audit};
use App\Services\Remote\{SshServiceInterface, Command};
use Illuminate\Support\Facades\{Gate, DB};
class BackupProjectJob implements ShouldQueue
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
            new \Illuminate\Queue\Middleware\WithoutOverlapping(
                "maintenance-" . $this->operationToken,
            )
                ->dontRelease()
                ->expireAfter(900),
        ];
    }
    public function handle(
        BackupManager $manager,
        SshServiceInterface $ssh,
    ): void {
        $b = Backup::findOrFail($this->backupId);
        $p = $b->project;
        if (
            $p->active_deployment_id !== 0 ||
            $p->active_operation_token !== $this->operationToken
        ) {
            return;
        }
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
            $manager->create($p, $b, $ssh);
            Audit::record(
                "BACKUP_PROJECT",
                "success",
                $p->id,
                $p->server_id,
                $this->userId,
            );
        } catch (\Throwable) {
            $b->update(["status" => "failed"]);
            Audit::record(
                "BACKUP_PROJECT",
                "failed",
                $p->id,
                $p->server_id,
                $this->userId,
            );
        } finally {
            Project::whereKey($p->id)
                ->where("active_deployment_id", 0)
                ->where("active_operation_token", $this->operationToken)
                ->update([
                    "active_deployment_id" => null,
                    "active_operation_token" => null,
                ]);
        }
    }
    public function failed(?\Throwable $e): void
    {
        if ($b = Backup::find($this->backupId)) {
            $b->update(["status" => "failed"]);
            Project::whereKey($b->project_id)
                ->where("active_deployment_id", 0)
                ->where("active_operation_token", $this->operationToken)
                ->update([
                    "active_deployment_id" => null,
                    "active_operation_token" => null,
                ]);
        }
    }
}
