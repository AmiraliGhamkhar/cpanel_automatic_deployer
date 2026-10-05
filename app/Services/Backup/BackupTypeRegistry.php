<?php

namespace App\Services\Backup;

use App\Services\{BackupManager, FileBackupManager};
use InvalidArgumentException;

/** Maps a backup type to its manager, so new types stay one small class each. */
class BackupTypeRegistry
{
    public function for(string $type): BackupManager
    {
        return match ($type) {
            "files" => new FileBackupManager(),
            "database" => new DatabaseBackupManager(),
            default => throw new InvalidArgumentException("Unsupported backup type."),
        };
    }
}
