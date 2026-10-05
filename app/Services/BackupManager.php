<?php
namespace App\Services;
use App\Models\{Project, Backup};
use App\Services\Remote\{SshServiceInterface, Command};
interface BackupManager
{
    public function create(
        Project $project,
        Backup $backup,
        SshServiceInterface $ssh,
    ): void;
}
