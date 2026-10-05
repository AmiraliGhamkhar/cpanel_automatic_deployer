<?php
namespace App\Services\Deployment;
use App\Models\{Deployment, Project, User};
use App\Services\{Audit, HealthCheckEngine};
use App\Services\Remote\{SshServiceInterface, GitHubService, Command};
use App\Services\Security\Input;
use Illuminate\Support\Facades\{DB, Gate};
use RuntimeException;
class DeploymentService
{
    public function __construct(
        private SshServiceInterface $ssh,
        private GitHubService $github,
        private StrategyRegistry $strategies,
        private HealthCheckEngine $health,
        private DeploymentLog $log,
    ) {}
    public function trigger(
        Project $project,
        User $user,
        string $branch,
        ?string $sha,
        bool $confirmed,
    ): Deployment {
        Gate::forUser($user)->authorize("deploy", $project);
        if (!$confirmed) {
            throw new RuntimeException(
                "Explicit deployment confirmation is required.",
            );
        }
        Input::branch($branch);
        if ($sha) {
            Input::commit($sha);
            if ($project->deployment_mode !== "ssh") {
                throw new RuntimeException(
                    "cPanel Git deployments always take the latest commit on the branch; deploy a pinned commit with SSH releases.",
                );
            }
        }
        return $this->queue($project, $user, "deploy", $branch, $sha, null);
    }
    public function queue(
        Project $project,
        User $user,
        string $kind,
        string $branch,
        ?string $sha,
        ?Deployment $target = null,
        ?int $sourceBackupId = null,
    ): Deployment {
        Gate::forUser($user)->authorize("deploy", $project);
        if (!in_array($kind, ["deploy", "rollback", "restore"], true)) {
            throw new RuntimeException("Invalid operation.");
        }
        if ($kind === "restore" && !$sourceBackupId) {
            throw new RuntimeException(
                "A file restore requires the backup it restores.",
            );
        }
        // Release an operation abandoned by a dead worker before reporting busy.
        app(\App\Services\Operations\StaleOperationReaper::class)->reap($project);
        return DB::transaction(function () use (
            $project,
            $user,
            $kind,
            $branch,
            $sha,
            $target,
            $sourceBackupId,
        ) {
            \App\Models\Server::lockForUpdate()->findOrFail(
                $project->server_id,
            );
            $p = Project::lockForUpdate()->findOrFail($project->id);
            if ($p->active_deployment_id !== null) {
                throw new RuntimeException(
                    "Another deployment or maintenance operation is active.",
                );
            }
            $this->validate($p);
            $d = $p->deployments()->create([
                "triggered_by" => $user->id,
                "branch" => $branch,
                "commit_hash" => $sha,
                "kind" => $kind,
                "target_deployment_id" => $target?->id,
                "source_backup_id" => $sourceBackupId,
            ]);
            $p->update(["active_deployment_id" => $d->id]);
            Audit::record(
                self::auditAction($kind),
                "queued",
                $p->id,
                $p->server_id,
                $user->id,
            );
            \App\Jobs\DeployProjectJob::dispatch($d->id);
            return $d;
        });
    }
    public function validate(Project $p): void
    {
        $s = $p->server;
        if (!$p->enabled || !$s->enabled) {
            throw new RuntimeException("Project or server is disabled.");
        }
        Input::repository($p->repository_url);
        Input::branch($p->branch);
        Input::path(
            $p->remote_path,
            $s->ssh_username ?? $s->cpanel_username,
        );
        if ($p->project_type === "static" && !empty($p->environment_config)) {
            throw new RuntimeException(
                "Static projects must not publish managed secrets. Remove environment values before deploying.",
            );
        }
        if ($p->project_type === "custom") {
            throw new RuntimeException(
                "Custom projects require a reviewed strategy implemented in application code.",
            );
        }
        if (
            !$s->last_health_check_at ||
            $s->last_health_check_at->lt(now()->subDay())
        ) {
            throw new RuntimeException(
                "Run Test Connection first; capability results must be less than 24 hours old.",
            );
        }

        // cPanel Git Version Control: no SSH, no release directory, provider
        // owns the checkout and the file layout.
        if ($p->deployment_mode === "cpanel_git") {
            if (
                !in_array(
                    $s->connection_mode,
                    ["cpanel_api", "cpanel_api_and_ssh"],
                    true,
                ) ||
                !$s->cpanel_api_token
            ) {
                throw new RuntimeException(
                    "cPanel Git mode requires a cPanel API token for this server.",
                );
            }
            if (
                ($s->capabilities["cpanel_git"]["status"] ?? "") !==
                "available"
            ) {
                throw new RuntimeException(
                    "Unsupported on this host: the cPanel Git Version Control API did not respond. Run Test Connection and review the provider's UAPI permissions.",
                );
            }
            return;
        }

        if ($p->deployment_mode !== "ssh") {
            throw new RuntimeException(
                "Unsupported deployment mode. Use SSH or cPanel Git Version Control.",
            );
        }
        if (!in_array($p->release_strategy, ["symlink", "in_place"], true)) {
            throw new RuntimeException(
                "Unknown release strategy. Use symlink releases or an in-place copy.",
            );
        }
        if ($p->release_strategy === "in_place" && $p->public_path !== null) {
            throw new RuntimeException(
                "In-place releases serve the deployment directory directly; a public subdirectory hint is not supported. Remove the public path or use symlink releases.",
            );
        }
        $required = array_merge(
            ["ssh", "sftp"],
            $p->release_strategy === "symlink"
                ? ["symlink"]
                : ["tar", "timeout"],
            $this->strategies->for($p->project_type)->requirements(),
        );
        if ($p->settings["build"] ?? false) {
            $required = array_merge($required, ["node", "npm"]);
        }
        foreach ($required as $cap) {
            if (($s->capabilities[$cap]["status"] ?? "") !== "available") {
                throw new RuntimeException(
                    "Unsupported on this host: " . $cap . " is unavailable.",
                );
            }
        }
        if (
            in_array($p->project_type, ["node", "python"]) &&
            ($p->settings["restart"] ?? "none") !== "passenger"
        ) {
            throw new RuntimeException(
                "Node/Python requires a provider-configured Passenger application.",
            );
        }
        if (
            ($p->settings["restart"] ?? "none") === "passenger" &&
            ($s->capabilities["cpanel_applications"]["status"] ?? "") !==
                "available" &&
            ($s->capabilities["passenger"]["status"] ?? "") !== "available"
        ) {
            throw new RuntimeException(
                "Passenger/Application Manager was not detected.",
            );
        }
    }
    public function execute(Deployment $d): void
    {
        if ($d->status !== "pending") {
            return;
        }
        $p = $d->project;
        $user = User::findOrFail($d->triggered_by);
        Gate::forUser($user)->authorize("deploy", $p);
        if ((int) $p->active_deployment_id !== $d->id) {
            throw new RuntimeException(
                "Project operation lock is not owned by this deployment.",
            );
        }
        $d->transition("running");
        $d->update(["started_at" => now()]);
        $this->log->step(
            $d,
            "Validate configuration",
            fn() => $this->validate($p),
        );
        if ($p->deployment_mode === "cpanel_git") {
            // cPanel owns the checkout; the panel only orchestrates and verifies.
            app(CpanelGitDeploymentService::class)->execute($d);
            return;
        }
        $this->log->step(
            $d,
            "Connect with pinned SSH host key",
            fn() => $this->ssh->connect($p->server),
        );
        $base = $p->remote_path;
        $this->log->step(
            $d,
            "Verify dedicated project directory",
            function () use ($base, $p) {
                $this->ssh->run(
                    Command::initialize(
                        $base,
                        $p->id,
                        $p->server->ssh_username,
                    ),
                );
                $this->ssh->run(Command::prepare($base, $p->id));
            },
        );
        $previous = $p->current_deployment_id
            ? Deployment::find($p->current_deployment_id)
            : null;
        $d->update(["previous_release_path" => $previous?->release_path]);
        if ($d->kind === "rollback") {
            $target = app(RollbackDeploymentService::class)->select(
                $p,
                (int) $d->target_deployment_id,
            );
            $release = $target->release_path;
            $this->log->step(
                $d,
                "Verify rollback release exists",
                function () use ($release) {
                    if (!$this->ssh->exists($release)) {
                        throw new RuntimeException(
                            "Rollback release is missing.",
                        );
                    }
                },
            );
            $d->update([
                "commit_hash" => $target->commit_hash,
                "release_path" => $release,
            ]);
        } elseif ($d->kind === "restore") {
            $release = $base . "/releases/" . $d->id;
            $d->update(["release_path" => $release]);
            $backup = \App\Models\Backup::find((int) $d->source_backup_id);
            if (
                !$backup ||
                $backup->project_id !== $p->id ||
                $backup->type !== "files" ||
                $backup->status !== "success"
            ) {
                throw new RuntimeException(
                    "The selected backup is no longer available for restore.",
                );
            }
            $this->log->step(
                $d,
                "Extract backup archive",
                fn() => $this->ssh->run(
                    Command::restoreArchive($base, $release, $backup->id),
                ),
            );
            if ($p->project_type !== "static") {
                $this->log->step(
                    $d,
                    "Restore protected environment",
                    function () use ($p, $base, $release) {
                        $this->writeEnvironment($p);
                        $this->ssh->run(
                            Command::linkEnvironment($base, $release),
                        );
                    },
                );
            }
            if ($p->project_type === "laravel") {
                $this->log->step(
                    $d,
                    "Link persistent Laravel storage",
                    fn() => $this->ssh->run(Command::storage($base, $release)),
                );
            }
        } else {
            $sha = $this->log->step(
                $d,
                "Resolve GitHub commit",
                fn() => $this->github->commit(
                    $p->repository_url,
                    $d->branch,
                    $d->commit_hash,
                ),
            );
            $release = $base . "/releases/" . $d->id;
            $d->update(["commit_hash" => $sha, "release_path" => $release]);
            $this->log->step(
                $d,
                "Clone and verify branch ancestry",
                fn() => $this->ssh->run(
                    Command::clone(
                        $p->repository_url,
                        $d->branch,
                        $sha,
                        $release,
                    ),
                ),
            );
            $this->log->step(
                $d,
                "Remove Git metadata from served release",
                fn() => $this->ssh->run(Command::removeGitMetadata($release)),
            );
            if ($p->project_type !== "static") {
                $this->log->step(
                    $d,
                    "Write protected environment",
                    function () use ($p, $base, $release) {
                        $this->writeEnvironment($p);
                        $this->ssh->run(
                            Command::linkEnvironment($base, $release),
                        );
                    },
                );
            }
            if ($p->project_type === "laravel") {
                $this->log->step(
                    $d,
                    "Link persistent Laravel storage",
                    fn() => $this->ssh->run(Command::storage($base, $release)),
                );
            }
            $requirements = $this->ssh->exists($release . "/requirements.txt");
            foreach (
                $this->strategies
                    ->for($p->project_type)
                    ->steps($p, $requirements)
                as $title => $operation
            ) {
                $this->log->step(
                    $d,
                    $title,
                    fn() => $this->ssh->run(
                        Command::install($release, $operation),
                    ),
                );
            }
        }
        $candidates = $this->strategies
            ->for($p->project_type)
            ->verificationCandidates();
        if ($candidates !== []) {
            // Verified while the previous release is still serving.
            $this->log->step(
                $d,
                "Verify release contents",
                fn() => $this->ssh->run(Command::verifyRelease($release, $candidates)),
            );
        }

        $inPlace = $p->release_strategy === "in_place";
        $this->log->step(
            $d,
            $inPlace ? "Activate in-place release" : "Activate release",
            fn() => $this->ssh->run(
                $inPlace
                    ? Command::inPlaceActivate($base, $release, $p->id, $d->id)
                    : Command::activate($base, $release, $p->id),
            ),
        );
        if ($inPlace && $candidates !== []) {
            // The live directory is what the provider serves: verify the copy.
            $this->log->step(
                $d,
                "Verify deployed files",
                fn() => $this->ssh->run(
                    Command::verifyRelease($p->livePath(), $candidates),
                ),
            );
        }
        // Track the actual filesystem switch even when the subsequent health check fails.
        $p->update(["current_deployment_id" => $d->id]);
        $this->log->step(
            $d,
            "Apply restart strategy",
            fn() => $this->restart($p),
        );
        $this->log->step($d, "Verify configured health check", function () use (
            $p,
        ) {
            $result = $this->health->check($p, $this->ssh);
            // Record the probe on the project so the UI never shows a stale verdict.
            Project::whereKey($p->id)->update([
                "health_checked_at" => now(),
                "health_check_message" => mb_substr($result->message, 0, 500),
            ]);
            if (!$result->passed) {
                throw new RuntimeException(
                    "Health check failed: " . $result->message,
                );
            }
        });
        DB::transaction(function () use ($d, $p) {
            $d->transition($d->kind === "rollback" ? "rolled_back" : "success");
            $d->update([
                "finished_at" => now(),
                "duration" => (int) $d->started_at->diffInSeconds(now()),
                "rollback_available" => true,
            ]);
            $p->update(["active_deployment_id" => null, "status" => "running"]);
            Audit::record(
                self::auditAction($d->kind),
                "success",
                $p->id,
                $p->server_id,
                $d->triggered_by,
            );
        });
    }
    /** Audit action for an operation kind, so history reads consistently. */
    public static function auditAction(string $kind): string
    {
        return match ($kind) {
            "rollback" => "ROLLBACK_PROJECT",
            "restore" => "RESTORE_BACKUP",
            default => "DEPLOY_PROJECT",
        };
    }

    /** Short, first-party failure summary for lists; detail lives in failure_detail. */
    public function failureSummary(Deployment $d): string
    {
        if ($d->failure_step) {
            return mb_substr(
                "Failed step: " . $d->failure_step . ".",
                0,
                250,
            );
        }
        return "Deployment failed before a step reported a reason. Inspect the log, provider limits and the queue worker.";
    }

    public function writeEnvironment(Project $p): void
    {
        $this->ssh->upload(
            $p->remote_path . "/shared/.env",
            \App\Services\EnvironmentFile::encode($p->environment_config ?? []),
        );
    }
    public function restart(Project $p): void
    {
        if (($p->settings["restart"] ?? "none") === "passenger") {
            $this->ssh->run(Command::install($p->livePath(), "passenger"));
        }
    }
    public function fail(int $id): void
    {
        DB::transaction(function () use ($id) {
            $d = Deployment::lockForUpdate()->find($id);
            if (!$d || !in_array($d->status, ["pending", "running"])) {
                return;
            }
            $d->transition("failed");
            $d->update([
                "finished_at" => now(),
                "duration" => $d->started_at
                    ? (int) $d->started_at->diffInSeconds(now())
                    : 0,
                "failure_reason" => $this->failureSummary($d),
                // A release was switched but is not healthy: offer the way back.
                "rollback_available" => $d->previous_release_path !== null,
            ]);
            Project::whereKey($d->project_id)
                ->where("active_deployment_id", $id)
                ->update([
                    "active_deployment_id" => null,
                    "status" => "failed",
                ]);
            Audit::record(
                self::auditAction($d->kind),
                "failed",
                $d->project_id,
                null,
                $d->triggered_by,
            );
        });
    }
}
