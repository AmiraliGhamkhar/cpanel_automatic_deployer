<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Server extends Model
{
    protected $attributes = [
        "enabled" => true,
        "status" => "unknown",
        "port" => 2083,
        "ssh_port" => 22,
        "connection_mode" => "cpanel_api",
    ];
    protected $guarded = ["id"];
    protected $hidden = ["cpanel_api_token", "ssh_private_key"];
    protected function casts(): array
    {
        return [
            "cpanel_api_token" => "encrypted",
            "ssh_private_key" => "encrypted",
            "capabilities" => "array",
            "enabled" => "boolean",
            "last_health_check_at" => "datetime",
        ];
    }
    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
