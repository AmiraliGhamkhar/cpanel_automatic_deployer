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

## Backups

Backup acquires the same project exclusion as deployment and requires a verified active release plus tar. It archives release files on the target, excluding .git and .env. It is **not** a complete application backup: databases, shared storage/uploads and external files are excluded. Cached application configuration or other release files may still contain secrets; archives remain private and must be handled as sensitive.

Automatic restore/delete/download, database backups and off-site copies are not implemented. Use your provider's tested backup tooling for disaster recovery. A same-host archive does not protect against loss of that host. Restore manually into an isolated staging location after validating the archive and compatibility; do not overwrite a live directory from an unverified archive.
