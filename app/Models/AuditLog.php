<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AuditLog extends Model
{
    protected $guarded = ["id"];
    protected $hidden = [];
    protected function casts(): array
    {
        return ["created_at" => "datetime"];
    }
    public $timestamps = false;
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function project()
    {
        return $this->belongsTo(Project::class);
    }
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
