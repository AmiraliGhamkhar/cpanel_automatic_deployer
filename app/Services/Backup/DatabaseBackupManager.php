<?php

namespace App\Services\Backup;

use App\Models\{Backup, Project};
use App\Services\{Audit, BackupManager};
use App\Services\Remote\{Command, SshServiceInterface};
use RuntimeException;

/**
 * Database backup by mysqldump.
 *
 * Credentials come from the project's managed environment, are written to a
 * mode-0600 option file over SFTP and removed by the remote script even when
 * the dump fails. Restores are explicit and confirmed; nothing here runs
 * automatically.
 */
class DatabaseBackupManager implements BackupManager
{
    public function create(Project $project, Backup $backup, SshServiceInterface $ssh): void
    {
        $credentials = $this->requireCredentials($project);
        if (
            ($project->server->capabilities["mysqldump"]["status"] ?? "") !==
            "available"
        ) {
            throw new RuntimeException(
                "Unsupported on this host: mysqldump is unavailable, so the panel cannot dump this database. Use cPanel's backup tool instead.",
            );
        }

        $optionFile = $project->remote_path . "/shared/.mysql-" . $backup->id . ".cnf";
        $backup->update(["status" => "running"]);

        // SFTP write is atomic (temp file + POSIX rename) and mode 0600.
        $ssh->upload($optionFile, $credentials->optionFile());

        $options = $project->setting("db_dump_options", []);
        $ssh->run(
            Command::databaseDump(
                $project->remote_path,
                $backup->id,
                $credentials->database,
                $optionFile,
                is_array($options) ? array_values($options) : [],
            ),
        );

        $backup->update([
            "status" => "success",
            "database_name" => $credentials->database,
            "path" =>
                $project->remote_path .
                "/backups/database-" .
                $backup->id .
                ".sql.gz",
            "metadata" => [
                "restore" =>
                    "Restore is explicit and overwrites the database. Rollback of a release never touches the database.",
                "credentials_fingerprint" => $credentials->fingerprint(),
                "host" => $credentials->host,
                "options_used" => is_array($options) ? array_values($options) : [],
            ],
        ]);
    }

    /** Import a dump back into the same database with the same credentials. */
    public function restore(Project $project, Backup $backup, SshServiceInterface $ssh): void
    {
        $credentials = $this->requireCredentials($project);
        $expected = $backup->metadata["credentials_fingerprint"] ?? null;
        if (
            $backup->database_name !== $credentials->database ||
            $expected !== $credentials->fingerprint()
        ) {
            throw new RuntimeException(
                "This dump was taken from a different database or with different credentials. Refusing to import it.",
            );
        }

        $optionFile = $project->remote_path . "/shared/.mysql-" . $backup->id . ".cnf";
        $ssh->upload($optionFile, $credentials->optionFile());
        $ssh->run(
            Command::databaseRestore(
                $project->remote_path,
                $backup->id,
                $credentials->database,
                $optionFile,
            ),
        );
        Audit::record(
            "RESTORE_BACKUP",
            "database_imported",
            $project->id,
            $project->server_id,
        );
    }

    private function requireCredentials(Project $project): DatabaseCredentials
    {
        $credentials = DatabaseCredentials::fromEnvironment(
            $project->environment_config ?? [],
        );
        if (!$credentials) {
            throw new RuntimeException(
                "Set DATABASE_URL or DB_DATABASE/DB_USERNAME/DB_PASSWORD in the project environment before backing up a database.",
            );
        }
        return $credentials;
    }
}
