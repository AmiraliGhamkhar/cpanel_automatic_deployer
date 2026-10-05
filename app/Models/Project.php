<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Project extends Model
{
    protected $attributes = [
        "enabled" => true,
        "status" => "unknown",
        "branch" => "main",
        "repository_provider" => "github",
        "deployment_mode" => "ssh",
        "release_strategy" => "symlink",
    ];
    protected $guarded = ["id"];
    protected $hidden = ["environment_config"];
    protected function casts(): array
    {
        return [
            "environment_config" => "encrypted:array",
            "settings" => "array",
            "enabled" => "boolean",
            "active_deployment_id" => "integer",
            "current_deployment_id" => "integer",
        ];
    }
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
    public function deployments()
    {
        return $this->hasMany(Deployment::class);
    }
    public function backups()
    {
        return $this->hasMany(Backup::class);
    }
}
