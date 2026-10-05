# Security and quality audit — findings and remediation

Date: 2026-10-05. Scope: the whole repository (Laravel 12 + Filament 3 control plane
that manages remote cPanel accounts). The baseline commit contained a 28-byte
README, so every line of application code reviewed here was written during this
engagement; this document therefore reports findings against the code as built,
including capabilities that were deliberately **not** built, and it keeps the
unverified parts explicit.

## Method and threat model

- The control plane is the high-value asset: it stores SSH private keys and cPanel
  API tokens for every managed account. A compromise there compromises all targets.
- Hosting targets are separate trust domains. A repository deployed to a target may
  be hostile; the target account itself is assumed to be operated by the same
  administrator but not necessarily hardened.
- Review covered: Filament resources/actions/policies, service layer (configuration,
  deployment, rollback, backups, health), remote adapters (SSH/SFTP, cPanel UAPI,
  GitHub), queue jobs, migrations, encrypted casts, logging, `docs/`, and the test
  suite. Every fix below has a test, a CI step, or both. Nothing here is claimed as
  verified without naming the evidence.

## Findings and remediation

| # | Severity | Finding | Remediation | Evidence |
|---|---|---|---|---|
| 1 | Critical | Remote command construction accepted interpolated values, so an operator-supplied or provider-derived string could reach the target shell as syntax. | `Remote/Command.php` has a private constructor; only predefined builder methods can produce a command and every parameter goes through `Security/Input` validation plus shell quoting. No console, no free-form command field. | `tests/Unit/InputTest.php`, `tests/Feature/DeploymentSafetyTest.php` |
| 2 | Critical | "Custom" projects could be deployed with operator-supplied commands, which is remote shell execution by design. | Custom projects are not deployable from the UI: `CustomDeploymentStrategy::steps()` refuses until a reviewed, in-code strategy exists. | `StrategyTest`, `CustomDeploymentStrategy.php` |
| 3 | High | Environment values are written to a target `.env`; a newline in a submitted value could inject an extra key or a second line. | Values are validated as single-line dotenv values (`Input::envValue`), quoted on write, and uploaded through an empty `0600` file plus POSIX rename. Injection attempts are rejected. | `tests/Unit/InputTest.php`, `tests/Feature/SecretSetTest.php` |
| 4 | High | Remote stdout/stderr and cPanel payloads could carry secrets (tokens printed by a package, `.env` contents in an error) into logs and the UI. | Structured, first-party deployment events; remote output is bounded and passes a redactor before storage; cPanel responses expose only allowlisted fields; audit records never contain values. | `tests/Feature/DeploymentSafetyTest.php` (redaction), `DeploymentLog`, `Redactor`, `CpanelUapiService::inspect()` |
| 5 | High | Health checks and other outbound HTTP could be pointed at internal services (SSRF), follow redirects, or send credentials to an arbitrary host. | `PublicHttp` allows HTTPS only, refuses embedded credentials and redirects, resolves and validates the destination address against reserved/private ranges, and applies bounded timeouts and body sizes. A test-only flag (`control.allow_private_targets`) exists for the loopback integration suite and is asserted to be off by default. | `tests/Feature/PublicHttpGuardTest.php`, `app/Services/Security/PublicHttp.php` |
| 6 | High | SSH without host-key verification is vulnerable to interception on a hosting network. | The panel pins a SHA256 host fingerprint per server, validates its format, compares it against the key the server actually presents and fails closed before authentication. Key material is write-only and encrypted at rest. | `SshService::connect()`, integration suite host-key mismatch case |
| 7 | High | Database credentials for backups could be exposed in process listings, shell history or the control plane. | Credentials are read from the target's own shared `.env`, written to a `0600` option file under `shared/`, passed to the client, and removed; extra client flags come from a whitelist (`db_dump_options`). Dumps stay on the target and are never pulled into the control plane. A fingerprint HMAC (keyed by `APP_KEY`) detects credential changes. | `tests/Feature/BackupAndRestoreTest.php`, `DatabaseBackupManager`, `DatabaseCredentials` |
| 8 | Medium | cPanel UAPI calls could be turned into arbitrary API requests with unvalidated parameters. | `CpanelUapiService` restricts endpoints to a fixed map, validates parameters per endpoint and returns only mapped fields. Unknown endpoints/parameters are rejected. | `tests/Feature/CpanelGitDeploymentTest.php`, `CpanelUapiService` |
| 9 | Medium | A project path could collide with another project, escape the account home, or take over an existing directory. | Paths are validated as `/home/<user>/<path>`, must not be a provider-reserved root, must not overlap another project on the same server, must be marker-owned or empty, and symlinked ancestors are rejected; activation never overwrites a real `current` directory. | `tests/Feature/ControlPlaneTest.php`, `tests/Unit/InputTest.php`, `DeploymentService` |
| 10 | Medium | A worker killed mid-operation left a project reservation that blocked all future deployments for that project. | `StaleOperationReaper` plus `php artisan control:reap` (with `--dry-run`/`--force`) releases operations that produced no terminal state within `control.stale_operation_after` (2700 s, floor 600 s) — deliberately above the longest job timeout so healthy work is never reaped. Cron line documented. | `tests/Feature/StaleOperationTest.php`, `docs/deployment.md` |
| 11 | Medium | In-place targets had no verified activation path or rollback and could silently serve a half-written tree. | In-place deployment verifies the release, records the failure step, refuses rollback (which is impossible to do safely in place), and archives the previous tree to `backups/inplace-<id>.tar.gz` before a backup/restore. | `tests/Feature/InPlaceDeploymentTest.php` |
| 12 | Medium | A rollback could target an unverified, foreign or failed release and flip the domain to arbitrary content. | `RollbackDeploymentService` accepts only a successful/rolled-back release of the same project whose directory exists, and records a new `rolled_back` deployment instead of rewriting history. | `tests/Feature/DeploymentSafetyTest.php` |
| 13 | Medium | Health verification was a bare boolean, so failures had no safe diagnostic and could be misreported as success. | `HealthCheckResult` carries a strategy-tagged result and a bounded, secret-free message; `failure_step` and `failure_detail` are persisted; success requires the configured strategy to pass after activation. TCP/process strategies exist for non-HTTP apps. | `tests/Feature/DeploymentSafetyTest.php`, `HealthCheckEngine` |
| 14 | Medium | Deploying an unverified checkout (partial clone, wrong branch) could activate an incomplete tree. | Verification candidates are declared per template (`config/deployment_templates.php`) and at least one must exist in the release; commit ancestry is checked; Git metadata is removed before activation. | `StrategyTest`, `DeploymentService` |
| 15 | Low | Project-type detection reported only a type, so an operator could not tell whether the panel guessed or matched a real marker. | Detection walks the repository tree and reports the matched marker (or "no known marker"); unknown types resolve to `custom`, which refuses to deploy. | `GitHubService::detectWithReason()`, `StrategyRegistry` |
| 16 | Low | The UI offered environment/rollback/restart/backup actions for transports that cannot support them, producing confusing failures. | Actions are hidden unless the project's `deployment_mode` is `ssh`; the cPanel Git deploy form explains its own limits; deployment records expose `rollback_available`. | `ProjectResource`, `CpanelGitDeploymentTest` |
| 17 | High (process) | CI could not see whether the application boots the way production runs it (config/route/view caches, migrations, admin command), so a whole class of release failures was invisible. | CI now performs a production-style boot (`migrate`, `config:cache`, `route:cache`, `view:cache`, `route:list`, `control:admin --help`, `control:reap --dry-run`) on every push. | `.github/workflows/tests.yml` |
| 18 | Critical (process) | `CpanelGitDeploymentTest` declared a private helper called `run()`, overriding PHPUnit's **final** `TestCase::run()`. That is a link-time fatal: the process died while loading test files, no test ran, the JUnit report stayed empty and CI reported only exit code 255. No local `php -l` could see it, and it silently blocked every push. | The helper is now `deploy()`. `tests/suite-hygiene.php` runs before the suite, reflects the final methods inherited from PHPUnit's and Laravel's `TestCase`, and fails on any redeclaration, plus PSR-4 name/path mismatches. `tests/report-ci.php` now extracts fatal lines and log head/tail as check-run annotations so a process-level death is readable without downloading logs. | `tests/suite-hygiene.php`, `.github/workflows/tests.yml`, `docs/testing.md` |
| 19 | Medium (process) | There was no end-to-end test of the SSH transport: the whole deployment path depended on mocks. | A real-SSH integration suite runs against a local `sshd` in CI: pinned host-key mismatch, `initialize/prepare/exists/upload/read` probes, a real `git clone` of `octocat/Hello-World`, symlink release switching and rollback, and an in-place archive. It is skipped unless `CONTROL_SSH_TEST_TARGET` is set. | `tests/Integration/RealSshDeploymentTest.php`, `docs/testing.md` |
| 20 | Low | Dependency resolution is unpinned because the development sandbox cannot reach packagist. | A manual `workflow_dispatch` workflow resolves dependencies on PHP 8.4, validates them and commits `composer.lock`. Until it is run, CI resolves fresh versions on every push — a reproducibility gap, not a security one. | `.github/workflows/lockfile.yml` |

## Verified vs unverified

Verified in CI (GitHub Actions):

- production-style boot, 68 test methods in the control-plane suites, the
  dependency-free security smoke checks, and the load-time hygiene guard;
- the real-SSH integration suite when a runner is available (real `sshd`,
  phpseclib SFTP, real GitHub clone, release switch, rollback, in-place archive);
- both suites are reported as annotations even when the runner cannot be acquired.

**Not** verified anywhere yet, and therefore not claimed:

- live cPanel/Iranian-provider behaviour: jailed shell, `PATH`, quota, symlink and
  POSIX-rename semantics, Passenger registration, `.cpanel.yml` task execution;
- provider-specific cPanel UAPI database-dump/backup function names, which is why
  database backups remain SSH-only and `cpanel_git` projects expose no backups;
- browser/Livewire interaction beyond HTTP feature tests (asset publishing, login,
  form behaviour);
- off-site backup/restore and any zero-downtime guarantee;
- a pinned dependency set (`composer.lock`).

## Residual risk and next steps

1. Run the Lockfile workflow once to commit `composer.lock`, then treat dependency
   bumps as reviewed changes.
2. Rehearse a full deployment on a disposable cPanel account, including a
   `cpanel_git` project with a `.cpanel.yml` that writes a marker file.
3. Add browser/Livewire smoke tests (login, project form, deploy confirmation).
4. Consider commit-identity verification in health checks (an exact HTTP 200 proves
   availability, not which revision is serving).
5. Decide whether cPanel UAPI database backups are worth implementing once the
   provider's function names are confirmed from official documentation.
