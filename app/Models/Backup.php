<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Backup extends Model
{
    protected $attributes = ["status" => "pending", "type" => "files"];
    protected $guarded = ["id"];
    protected $hidden = [];
    protected function casts(): array
    {
        return ["metadata" => "array"];
    }
    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
