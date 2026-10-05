<?php
namespace App\Services;
use App\Models\{Project, Backup, Deployment};
use App\Services\Remote\{SshServiceInterface, Command};
class FileBackupManager implements BackupManager
{
    public function create(
        Project $project,
        Backup $backup,
        SshServiceInterface $ssh,
    ): void {
        $d = Deployment::find($project->current_deployment_id);
        if (
            !$d ||
            !in_array($d->status, ["success", "rolled_back"]) ||
            ($project->server->capabilities["tar"]["status"] ?? "") !==
                "available"
        ) {
            throw new \RuntimeException(
                "A verified release and tar capability are required.",
            );
        }
        $backup->update(["status" => "running"]);
        $ssh->run(
            Command::archive(
                $project->remote_path,
                $d->release_path,
                $backup->id,
            ),
        );
        $backup->update([
            "status" => "success",
            "path" =>
                $project->remote_path . "/backups/" . $backup->id . ".tar.gz",
            "metadata" => [
                "deployment_id" => $d->id,
                "excludes" => [".env", ".git"],
                "scope" =>
                    "release files only; not database or external uploads",
                "restore" =>
                    "Manual provider-assisted restore; automatic restore is not supported.",
            ],
        ]);
    }
}
