<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Deployment extends Model
{
    protected $attributes = [
        "status" => "pending",
        "kind" => "deploy",
        "rollback_available" => false,
    ];
    protected $guarded = ["id"];
    protected $hidden = [];
    protected function casts(): array
    {
        return [
            "started_at" => "datetime",
            "finished_at" => "datetime",
            "rollback_available" => "boolean",
            "target_deployment_id" => "integer",
            "triggered_by" => "integer",
        ];
    }
    public function project()
    {
        return $this->belongsTo(Project::class);
    }
    public function actor()
    {
        return $this->belongsTo(User::class, "triggered_by");
    }
    /**
     * Parse the stored JSON-lines log into structured steps for the UI.
     *
     * The newest entry per step is kept so a retried step shows its latest
     * status rather than the "running" placeholder.
     *
     * @return list<array{time:string,step:string,status:string,message:string,duration:float|null}>
     */
    public function steps(): array
    {
        $steps = [];
        foreach (explode("\n", (string) $this->log_output) as $line) {
            $decoded = json_decode(trim($line), true);
            if (!is_array($decoded) || !isset($decoded["step"])) {
                continue;
            }
            $steps[$decoded["step"]] = [
                "time" => (string) ($decoded["time"] ?? ""),
                "step" => (string) $decoded["step"],
                "status" => (string) ($decoded["status"] ?? "unknown"),
                "message" => (string) ($decoded["message"] ?? ""),
                "duration" => isset($decoded["duration"]) && $decoded["status"] !== "running"
                    ? (float) $decoded["duration"]
                    : null,
            ];
        }
        return array_values($steps);
    }

    public function transition(string $next): void
    {
        $allowed = [
            "pending" => ["running", "cancelled", "failed"],
            "running" => ["success", "failed", "rolled_back"],
            "success" => [],
            "failed" => [],
            "cancelled" => [],
            "rolled_back" => [],
        ];
        if (!in_array($next, $allowed[$this->status] ?? [], true)) {
            throw new \LogicException("Invalid deployment transition");
        }
        $this->update(["status" => $next]);
    }
}
