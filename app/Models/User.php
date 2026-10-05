<?php
namespace App\Models;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
class User extends Authenticatable implements FilamentUser
{
    protected $attributes = ["is_admin" => false];
    protected $guarded = ["id"];
    protected $hidden = ["password", "remember_token"];
    protected function casts(): array
    {
        return ["password" => "hashed", "is_admin" => "boolean"];
    }
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin;
    }
}
