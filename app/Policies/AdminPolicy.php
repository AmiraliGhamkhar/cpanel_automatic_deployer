<?php
namespace App\Policies;
use App\Models\User;
class AdminPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }
    public function view(User $user, $record): bool
    {
        return $user->is_admin;
    }
    public function create(User $user): bool
    {
        return $user->is_admin;
    }
    public function update(User $user, $record): bool
    {
        return $user->is_admin;
    }
    public function delete(User $user, $record): bool
    {
        return false;
    }
    public function deploy(User $user, $record): bool
    {
        return $user->is_admin;
    }
    public function operate(User $user, $record): bool
    {
        return $user->is_admin;
    }
}
