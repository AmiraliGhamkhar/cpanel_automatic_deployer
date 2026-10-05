# Architecture and decisions

## Repository audit (2026-10-05)

The baseline commit contained only a 28-byte README. There were no routes, database, application, deployment assumptions or legacy components to preserve. Native PHP/Composer were absent. Debian/Composer downloads were blocked. Laravel 12 / Filament 3 and PHP 8.4+ were selected as requested. No unrelated functionality was removed.

## Modular monolith

```text
Browser → Filament / Livewire → policies → application services
                                      ├─ configuration / environment
                                      ├─ transactional database queue
                                      ├─ deployment / rollback strategies
                                      ├─ SSH/SFTP adapter (phpseclib)
                                      ├─ cPanel UAPI adapter (Laravel HTTP)
                                      ├─ GitHub API adapter
                                      ├─ health checker / backup manager
                                      └─ PostgreSQL + encrypted model casts
```

The control plane and hosting targets are separate trust domains. Jobs serialize only record IDs and actor IDs. Worker authorization is rechecked; disabling the actor or target blocks future work. A disabled optional capability does not invalidate a functioning transport.

- `ConfigurationService`: validation, path isolation, write-only credentials and audit.
- `DeploymentService`: preflight, transactionally queue, orchestrate structured steps and track actual activation.
- `Strategies/*`: framework-specific allowlisted operations. Migrations are opt-in.
- `RollbackDeploymentService`: restrict target selection to verified, same-project releases.
- `Remote/Command`: private constructor; only predefined builders can produce shell commands.
- `Remote/SshService`: pinned key verification, bounded command output, SFTP operations.
- `Remote/CpanelUapiService`: endpoint allowlist, safe failure categories, no raw payload to UI.
- `ProjectOperations`: confirmed environment changes and maintenance exclusion.
- `BackupManager`: interface; `FileBackupManager` archives release files only.
- `PublicHttp`: HTTPS, no redirects, DNS address validation/pinning, bounded responses/timeouts.

## Data / concurrency

Servers have projects; projects have deployments and backups. Foreign keys restrict deleting records needed by history. Secrets are encrypted casts and hidden from serialization. Deployment timestamps/status/commit and relationship indexes support history queries. Project directories cannot overlap on the same server; identical account paths on different servers are valid.

`active_deployment_id` is a database-locked reservation: null means idle, a positive ID means a deployment, zero means queued/running maintenance. A UUID maintenance token fences stale or duplicate maintenance jobs so they cannot clear a newer operation’s reservation. All mutating project operations test **null**, not truthiness. The default database connection holds both queue jobs and domain records, so insertion and reservation commit together. Record-level locking is intended for PostgreSQL/MySQL. `current_deployment_id` changes immediately after the remote switch, even if health later fails.

State transitions: pending → running → success/failed/rolled_back. Failed/cancelled are also legal from pending; no UI cancellation is supplied because killing a running SSH operation safely is not implemented. Terminal records are not reused. Rollback creates its own record, preserving the original release's success history. No automatic rollback or migration reversal occurs.

A deployment job has one attempt. Queue middleware prevents duplicate simultaneous delivery of the same deployment. Exceptions produce a fixed safe failure message and release the reservation; timeout failure hooks do the same when Laravel invokes them. SIGKILL or machine failure can leave a reservation: recovery is deliberately operator-controlled after checking remote processes. There is no unsafe “force unlock” button.

## Deliberate constraints

- No Redis, microservices, scheduler orchestration or frontend build pipeline.
- No user-supplied commands, custom script textarea, root assumptions or OS package installs.
- Initial reliable transport is SSH releases. cPanel API inspection does not imply Git/app-management write support.
- Symlink and SFTP POSIX rename support is verified by actual operations, not inferred solely from binary presence.
- Structured logs omit remote stdout rather than attempting to guarantee arbitrary-output redaction.
- Database and environment rollback are separate operational concerns; a release rollback cannot guarantee schema compatibility.
- No production verification claim until Composer/Filament tests and a live provider rehearsal pass.
