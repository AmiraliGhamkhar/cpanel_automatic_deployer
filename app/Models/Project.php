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
    /** Read a deployment setting with a default, keeping settings access consistent. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = $this->settings ?? [];
        return array_key_exists($key, $settings) && $settings[$key] !== null
            ? $settings[$key]
            : $default;
    }

    /**
     * Absolute path the provider must serve.
     *
     * Symlink releases expose `<path>/current`; in-place releases are copied
     * into a real `<path>/app` directory because some hosts do not follow
     * symlinks reliably.
     */
    public function livePath(): string
    {
        return $this->remote_path .
            ($this->release_strategy === "in_place" ? "/app" : "/current");
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
