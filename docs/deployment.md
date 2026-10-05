# Deployment, environment and rollback

## Configuration

Configuration lives in project records, not hard-coded project names. The UI exposes GitHub URL/branch, project type, transport, release strategy, dedicated remote path, health URL, migrations/build toggles and a restart strategy. `public_path` and `entrypoint` are validated provider-configuration hints, not automatic cPanel changes. Runtime selection must be configured at the provider; arbitrary runtime paths/commands are intentionally not accepted.

```text
/home/account/apps/site/
  .control-project-<project-id>
  releases/<deployment-id>/
  shared/.env                 (0600)
  shared/storage/             (Laravel persistent uploads/sessions/logs)
  backups/<backup-id>.tar.gz   (private account-local release archive)
  current -> releases/<deployment-id>
```

### Pipeline

1. Authorize the actor; confirm the action; reserve the project and queue atomically.
2. Recheck actor, enabled flags, fresh capabilities, transport, paths and restart support in the worker.
3. Connect with verified host key; establish/check the dedicated directory marker.
4. Resolve GitHub commit, clone the branch into a new release, check commit ancestry and checkout detached.
5. Remove Git metadata from the served release. For non-static apps, write shared environment through a private temporary SFTP file and atomic POSIX rename; link into the release.
6. Laravel: link shared storage, Composer install, optional `artisan migrate --force`, config/view cache. Python: venv + requirements or local pyproject install. Node: locked `npm ci`, optional build. Static: optional npm install/build.
7. Create a temporary symlink and atomically rename it to `current`. Never overwrite a real current directory.
8. Touch Passenger's `tmp/restart.txt` if configured; no systemd/process-kill fallback.
9. Check the public HTTPS endpoint for exact HTTP 200, at most three attempts with 0/2/5 second backoff. Only then mark success.

The provider's document root must use `current/public` for Laravel, or the correct static directory. Python/Node applications must load the shared .env themselves using an appropriate dotenv library; the panel does **not** source it as shell code or automatically configure Passenger's environment UI. Values are dotenv-quoted, not shell exports. Test your runtime's dotenv parser, especially values containing backslashes, quotes or dollar signs.

Configure the provider web server to deny dotfiles, especially `.env`, for all application roots. Laravel must serve only its public subdirectory, never the source root. Do not commit secrets or credential files to a static repository; the panel removes `.git` metadata but cannot identify every sensitive file in trusted source code.

Node requires a lockfile for `npm ci`. Python needs functioning venv/pip at account level. Laravel requires a valid APP_KEY and database configuration. Package-manager hooks execute trusted repository code. Provider runtime selection cannot be inferred from the system's default `php`, `python3` or `node`; ensure the account PATH resolves the intended versions.

## Logs / failures

Live logs poll every three seconds and store JSON lines with time, step, status, safe message and duration. Remote stdout/stderr is bounded but **never persisted**. This deliberately trades verbose package-error output for protection against novel secrets printed by dependencies. Investigate detailed package errors directly on the trusted target; do not paste private logs into the browser panel.

A failure after activation leaves the new release active and records that actual state. The panel does not pretend the old release is still serving. Choose explicit rollback after inspecting the failure. A health endpoint returning 200 verifies HTTP availability, not commit identity; make a project-specific health endpoint exercise the services it needs. TCP/process/body/commit-identity checks are future work.

## Environment

Static sites intentionally cannot store or receive managed environment secrets: their release directory may be served directly. Put only reviewed public build configuration in static repositories. For other project types, use Environment to add, replace or delete a key. The form displays names and masks only, never saved values/ciphertext. Changes are encrypted in the control-plane database and audited without values. A deployment or Passenger restart applies them to the target. Environment writes are single-line dotenv values with uppercase identifier keys. Laravel's cached config requires a new deployment after changes.

The remote application necessarily has access to plaintext secrets in a mode-0600 shared file; encryption at rest applies to the control-plane database, not to secrets while the target runtime uses them. Rollback does not revert shared environment, database state or uploads. Writing a shared environment during a failed deploy may already have changed the old app's environment; treat configuration changes as an explicit operational change.

## Rollback

Choose a different verified success/rollback release from the same project's history. The worker verifies that the directory exists, switches `current`, restarts if configured, and runs the same health check. A new `rolled_back` record preserves the original history and selected commit. Database migrations are never reversed automatically. Before enabling migrations, use backward-compatible schema changes or a separately reviewed restore plan.

## cPanel Git mode (`cpanel_git`)

This transport does not use SSH. It drives the account's own **Git Version Control** feature through the cPanel UAPI token already stored on the server record, so it works on hosts that allow the cPanel API but not SSH keys.

Prerequisites, configured in cPanel before the first deployment:

1. Clone the repository into the account through cPanel → Git Version Control (or let the deploy action do it when the repository is already registered). The repository directory is what `remote_path` must point at: it is the deployment root for this mode, not a releases directory.
2. Add a `.cpanel.yml` at the repository root containing the tasks to run after the pull. Without it the pull succeeds and **no** application step runs.
3. Make the working tree clean. cPanel silently skips `.cpanel.yml` tasks when the repository directory has untracked files; the deployment then "succeeds" without rebuilding anything.

Pipeline: `VersionControl/update` (pull the tracked branch) → `VersionControlDeployment/create` (run `.cpanel.yml` for the recorded HEAD) → the project's HTTPS health check.

Deliberate limits of this mode:

- **No releases and no rollback.** Files are pulled into one directory, so the panel hides the rollback, environment, restart and backup actions for these projects; the deployment record has no release path and reports `rollback_available = false`.
- **No commit pinning.** The branch tip is deployed. Pinned commits, tags and historical deployments are refused with an explicit message instead of silently deploying something else.
- **No environment-file management.** Use the cPanel UI (or the repository's own tooling) for `.env` content; the panel never writes into a `cpanel_git` directory.
- **Asynchronous tasks, synchronous verification.** cPanel runs `.cpanel.yml` tasks in the background and returns only a `deploy_id`/task id. The panel therefore treats the health check as the acceptance signal and records the task id in the log; a failing task may only become visible as a failed health check.
- **No panel-managed backups or restarts.** Passenger restarts and database dumps remain SSH-only features.

Because the deploy step runs only `.cpanel.yml`, review that file with the same care as a deploy script: it runs with the account's privileges in the account's home directory.

## Backups

Every backup acquires the same project exclusion as a deployment, so a backup and a deploy cannot overlap. Two kinds exist:

- **Release archive** (`backups/<backup-id>.tar.gz`): the files of the verified active release, excluding `.git` and `.env`. It is **not** a complete application backup — shared storage/uploads and external files are excluded, and cached framework configuration inside a release can still contain secrets. Treat archives as sensitive.
- **Database dump** (`backups/database-<backup-id>.sql.gz` or the configured client's extension): produced over SSH, using the credentials the application already has. `db_dump_options` is a whitelist of extra client flags; arbitrary flags are rejected. Credentials are read from the target's shared `.env` (`DatabaseCredentials::fromEnvironment()`), written to a `0600` option file under `shared/`, passed to the client, and removed afterwards. The dump never passes through control-plane storage.

Restores are explicit and are recorded as their own `restore` deployment: choose the database backup to restore, confirm, and the worker streams the dump back into the database named by the current credentials. A restore does **not** touch releases, and a release rollback does not touch data. Because a restore is destructive, run it only against a target you are willing to overwrite and take a fresh dump first.

Backup deletion removes the archive/dump on the target and the record; it is audited. Off-site copies, downloads to the control plane, scheduled backup rotation and point-in-time recovery are **not** implemented: use the provider's tooling for disaster recovery. A same-host archive does not protect against loss of that host.

Database backups are SSH-only on purpose. The UAPI DB-dump/backup function names for third-party providers are unverified, so `cpanel_git` projects do not expose database backups.

## Stale operations

A worker killed with SIGKILL, a disconnected queue or a host outage can leave a project reservation behind. `control:reap` releases operations that have produced no terminal state for `control.stale_operation_after` seconds (default 2700, floor 600).

```cron
*/5 * * * * cd /path/to/app && php artisan control:reap >> storage/logs/reap.log 2>&1
```

`--dry-run` reports what would be released; `--force` ignores the age threshold and is for confirmed incidents only. The command never touches deployments that are still progressing: it requires the record to be older than the threshold, marks it `failed` with a fixed safe message and clears the reservation. See docs/troubleshooting.md for manual recovery.
