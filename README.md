# cPanel Control

A personal, self-hosted Laravel 12 + Filament 3 control plane for heterogeneous cPanel accounts. The panel runs separately from the managed hosting accounts. No bot, SPA, Docker requirement, WHM, root access, Redis or systemd dependency on targets.

> **Implementation status:** this is the initial implementation, not a production-certified release. The starting repository contained only a README. PHP syntax and the dependency-free security checks were executed in the original sandbox. The full PHPUnit suite is now also exercised in GitHub Actions, including HTTP feature tests; see the latest CI result. Native PHP/Composer and dependency downloads remain unavailable in this sandbox, and browser/Livewire interaction testing remains outstanding. No live cPanel account has been tested. The complete requested acceptance workflow is therefore **not yet verified**. See the limitations below before connecting production credentials.

## Implemented scope

- Native Filament login, dashboard, server/project forms, deployment history, polling logs, backups and read-only audit history.
- Administrator-only policies, persistent Livewire authentication, CSRF, login throttling, session expiry and request throttling. No registration or default password.
- Encrypted, write-only cPanel tokens, SSH keys and project environment values. Pinned SSH host keys; verified TLS; public-network destination checks.
- cPanel UAPI capability inspection; SSH/SFTP diagnostics with runtime versions and disk availability when safely extractable.
- GitHub public-repository branch/commit resolution and project-type detection.
- Database-queued SSH release deployment with Laravel, Python, Node and static strategies. Explicit migrations only; no destructive database commands.
- Health-gated success, bounded retries, structured step status/duration logs, per-project exclusion and explicit rollback to a verified release.
- Passenger restart markers; protected environment uploads; file backup interface and on-host release archives.
- PHPUnit unit/feature/safety tests and a PHP 8.4 CI workflow.

## Compatibility / intentionally unavailable operations

| Operation | Current support |
|---|---|
| cPanel API-only hosts | Inspect capabilities; deployment is blocked with an explanation |
| SSH deployment | Key authentication, verified SHA256 fingerprint, SFTP, public GitHub clone, `git`, `timeout`, POSIX rename and symlinks required |
| Laravel | Composer install, persistent shared storage, cache configuration/views, optional migrations |
| Python | `python3` + venv; `requirements.txt` or `pyproject.toml`; preconfigured Passenger app |
| Node | `npm ci`, optional build; preconfigured Passenger app |
| Static | Repository or optional npm build; configure domain root to `current` or `current/dist` |
| Release rollback | Existing successful same-project release; no database/environment rollback |
| Backups | Active release archive on the same account; no automatic restore, database backup or off-site replication |
| In-place / cPanel Git deployment | Explicitly blocked, not simulated |
| Custom shell / process management | No arbitrary commands; custom strategy must be reviewed application code |
| Private repositories | GitHub metadata token supported; authenticated remote clone **not implemented** |
| Health checks | Public HTTPS port 443, exact HTTP 200, three attempts; TCP/process checks **not implemented** |
| Provider app registration | Manual in cPanel; no automatic runtime version, entrypoint, domain or document-root changes |

## Installation

Use a trusted control-plane host with **PHP 8.4+**, Composer 2, HTTPS, a database and the ability to run a persistent queue worker. Required PHP extensions include curl, intl, mbstring, openssl, PDO plus pdo_pgsql (or pdo_mysql), DOM/XML, tokenizer, fileinfo, session and zip. This host is not required to be a managed cPanel account.

```sh
composer install
cp .env.example .env
php artisan key:generate
# Configure APP_URL, PostgreSQL credentials and HTTPS session settings in .env.
php artisan migrate --force
php artisan control:admin you@example.com
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:work database --queue=default --tries=1 --timeout=1800 --sleep=2
```

The administrator command prompts privately for a password of at least 16 characters. It does not overwrite existing users. No seeded credentials exist. No Node build is required: Composer's Filament upgrade hook publishes native admin assets.

Set the web server's document root to `public/` only. Give the application user write access to `storage/` and `bootstrap/cache/`; never make the repository or `.env` public. Restrict `.env` to mode 0600. Use `composer install --no-dev` for production **after** dependency resolution and tests have passed. Generate, review and commit `composer.lock` in your trusted build environment; this sandbox could not resolve dependencies, so it does not contain a lockfile.

Use a process supervisor supplied by your control-plane host to keep the queue worker alive. A foreground worker is sufficient for development. For a cron-only control plane, run `php artisan queue:work database --stop-when-empty --tries=1 --timeout=1800` with a provider-approved single-worker lock; many providers kill long cron tasks, so a small VPS is preferable. Targets themselves do not need a worker.

### Database and configuration

PostgreSQL is the default. Set `DB_CONNECTION=mysql` and port 3306 for MariaDB/MySQL. SQLite is for tests/development, not concurrent production operations. Queue and cache tables are included in the migration. Jobs contain IDs, not credentials. Database queue insertion and the project operation lock share a transaction; workers cannot see uncommitted jobs. Queue `retry_after=2100` exceeds the job's 1800-second timeout. Automatic deployment retries are intentionally disabled.

The database queue is deliberately fixed in configuration: a sync queue would block requests and break the deployment contract. Back up the control-plane database **and** its `APP_KEY` separately, with encryption. Losing the key makes saved credentials unrecoverable. Never rotate it casually; plan Laravel key rotation and re-encryption.

For local development only, use `APP_ENV=local`, `SESSION_SECURE_COOKIE=false`, a local database and `php artisan serve --host=0.0.0.0`. Keep production `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and an HTTPS `APP_URL`. Configure trusted reverse proxies explicitly for your deployment; the app does not blindly trust forwarded headers.

## First project

1. Open `/admin` and log in.
2. Add a server using its public cPanel TLS hostname and port 2083. Choose API, SSH or both.
3. Save the API token and SSH key in write-only fields. Supply the SSH username, port and independently verified host fingerprint.
4. Confirm **Test connection**. The queue worker performs probes. Open **Diagnostics** for the capability matrix. Optional missing features are not fatal. Results expire for deployment preflight after 24 hours.
5. Add a project: server, canonical `https://github.com/owner/repo` URL, branch, type and a dedicated empty path such as `/home/account/apps/site`.
6. Set its health URL and restart/build/migration settings. Configure the provider's document root/Passenger app as described in [deployment.md](docs/deployment.md).
7. Use **Environment** to add necessary values. Existing values are never shown. Choose **Detect project type** if desired, then manually override in Settings if necessary.
8. Choose **Deploy**, enter a branch and optional full 40-character commit SHA, and explicitly confirm that the repository is trusted.
9. Open **History → Logs**. Pending/running status and sanitized step events refresh every three seconds. A successful command alone is insufficient: the configured HTTPS health check must pass.
10. Deploy again, then use **Rollback** to select a prior verified release. Confirm the database/environment caveat and inspect the new rollback history record and health result.
11. Environment changes apply on the next deploy or supported Passenger restart. For Laravel's cached config, deploy again. Health checks, file backups, diagnostics and audit history are available from the corresponding actions/resources.

## Tests

```sh
php tests/security-smoke.php
composer test
```

PHPUnit uses an isolated in-memory SQLite database and mocked remote adapters. Never point tests at production. CI installs dependencies on PHP 8.4 and runs both suites. Test source covers authorization, confirmation, encryption/serialization, invalid repository inputs, shell-injection prevention, strategy selection, optional capabilities, health-gated success, state transitions and rollback selection. A real-provider staging rehearsal is still required; mocks cannot establish provider compatibility.

Executed here: **30 security smoke checks passed** and **PHP syntax parsing passed** through a temporary PHP WebAssembly runtime. The full PHPUnit suite is subsequently exercised in GitHub Actions; consult its reported results. Browser interaction and live-provider integration tests remain unrun.

## Documentation

- [Architecture and decisions](docs/architecture.md)
- [cPanel setup](docs/cpanel-setup.md)
- [SSH setup](docs/ssh-setup.md)
- [GitHub setup](docs/github-setup.md)
- [Deployment, environment, backup and rollback](docs/deployment.md)
- [Troubleshooting and recovery](docs/troubleshooting.md)
- [Security and threat model](docs/security.md)

## Before production

Resolve and lock dependencies; run the full test suite; review security and templates; rehearse deploy → health failure → rollback on a disposable account; confirm provider symlink/Passenger/SFTP behavior; configure protected control-plane backups and worker supervision. Automatic archive restore, private clone credentials, non-symlink deployment, stronger MFA and provider-specific runtime adapters remain future work. Do not treat these as completed acceptance criteria.
