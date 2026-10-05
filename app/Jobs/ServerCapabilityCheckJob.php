<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\{Server, User};
use App\Services\{CapabilityDetector, Audit};
use Illuminate\Support\Facades\Gate;
class ServerCapabilityCheckJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 900;
    public function __construct(public int $serverId, public int $userId) {}
    public function handle(CapabilityDetector $detector): void
    {
        $s = Server::findOrFail($this->serverId);
        Gate::forUser(User::findOrFail($this->userId))->authorize(
            "operate",
            $s,
        );
        try {
            $detector->check($s);
            Audit::record(
                "TEST_SERVER",
                $s->fresh()->status,
                null,
                $s->id,
                $this->userId,
            );
        } catch (\Throwable) {
            $s->update(["status" => "unknown"]);
            Audit::record("TEST_SERVER", "failed", null, $s->id, $this->userId);
        }
    }
}
