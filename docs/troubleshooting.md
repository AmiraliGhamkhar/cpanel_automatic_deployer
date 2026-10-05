# Troubleshooting and recovery

| Symptom | Check |
|---|---|
| Pending forever | Database queue worker running, correct environment/database, queue `default`, writable logs/cache |
| Login/session issues | APP_KEY set, migrations applied, HTTPS APP_URL, secure cookies, correct trusted reverse proxy configuration |
| API unknown | Public DNS, TLS chain, port 2083, outbound firewall and provider API policy |
| API unavailable | Token scope/validity, username, endpoint support; optional endpoints need not all pass |
| SSH fails | Enabled transport, public hostname, port, authorized key, account name, independently verified fingerprint |
| Cannot upload environment | SFTP availability, POSIX rename support, quota, private directory permissions, unexpected .env symlink |
| Runtime unavailable | Provider-enabled runtime and account PATH; no apt/yum/global install fallback |
| Git/package step fails | Target outbound GitHub/Packagist/PyPI/npm access, public repository, branch ancestry, package lockfile/runtime compatibility |
| Directory refused | Existing unrelated files, overlapping project, symlinked parent, wrong account path, modified project marker |
| Activation fails | Provider forbids symlinks or GNU mv semantics; stale `.current-next`; a real directory named current |
| Python/Node restart fails | Application Manager/Passenger configured with current app root, correct startup file/runtime and dotenv loading |
| Health fails | Exact HTTPS URL returns 200 without redirect, certificate, provider document root, app/database readiness, public DNS |
| Laravel missing data | Shared storage linkage and provider public storage URL; database/uploads are not part of release rollback |
| Rollback unavailable | No previous successful release, current release selected, directory removed, different project, or unsupported in-place strategy |

## Stuck operation recovery

Automatic force-unlock is intentionally absent. A worker killed with SIGKILL, host outage or a broken queue connection can leave a reservation. On the trusted control-plane host:

1. Stop workers and inspect the queue/failed_jobs and deployment record. Do not retry a partially completed deployment blindly.
2. Check the remote account for still-running Git/build/install processes. Wait for/stop them using provider-approved controls; do not assume killing the PHP worker killed the remote process.
3. Inspect the project marker, `current` symlink, temporary `.current-next` and the relevant release. Do not delete releases as a debugging shortcut.
4. Reconcile actual current release and database state. After ensuring no job is still active, a trusted operator can invoke `DeploymentService::fail($deploymentId)` through `php artisan tinker` **if Tinker is separately installed**, or a reviewed one-off application command. The shipped app does not include Tinker or a force-unlock command. Maintenance reservations require a reviewed database repair; record an operational audit entry.
5. Restart one worker and create a new deployment/rollback record. Never replay unknown partial migration side effects.

Do not run `queue:retry all` for deployment jobs. They are intentionally single-attempt and do not re-execute terminal deployment records. Diagnose DB connectivity before clearing anything.

## Stale operations

`control:reap` releases operations that were abandoned by a dead worker. Run it from
cron every few minutes on hosts without a process supervisor:

```cron
*/5 * * * * cd /path/to/app && php artisan control:reap >> storage/logs/reap.log 2>&1
```

`php artisan control:reap --dry-run` prints what would be released without changing
anything; `--force` ignores the age threshold and requires a confirmed incident. The
command skips operations that are still progressing (see `control.stale_operation_after`
in `config/control.php`). If the queue worker is healthy, the only rows this command
should ever report are ones left by a killed worker or a lost database connection —
investigate before force-releasing.

## Validation remaining

Dependency installation and the full PHPUnit suite run in GitHub Actions: a
control-plane job plus an integration job that runs against a real `sshd` on the
runner. That still does not prove provider-specific behaviour. Before using
production secrets, in your own deployment environment:

1. Run `composer install`, `composer test`, and a real browser/Livewire smoke test.
   Check published Filament assets and the login route.
2. Generate and commit a lockfile only after successful resolution
   (`workflow_dispatch` run of `.github/workflows/lockfile.yml`).
3. Rehearse one disposable Iranian hosting account end to end: its jail, PATH,
   quota, symlink/POSIX-rename behaviour and Passenger configuration cannot be
   verified by mocks or by loopback SSH.
4. Rehearse a `cpanel_git` project against a real account, including a `.cpanel.yml`
   task that writes a marker file, to confirm the provider's Git Version Control
   implementation matches the documented sequence.
