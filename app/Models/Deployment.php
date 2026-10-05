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
