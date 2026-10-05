# Testing

Two suites exist, described in `phpunit.xml`:

- **Control plane** (`tests/Unit`, `tests/Feature`): pure logic and HTTP/Livewire
  feature tests on an in-memory SQLite database with fake SSH/GitHub/cPanel
  adapters. Safe and fast; runs on every push.
- **Integration** (`tests/Integration`): the real thing over loopback. It starts
  from a real SSH server, runs the allowlisted commands with the real
  `SshService`, clones a real public repository, switches a real release
  symlink, archives an in-place tree and performs a real rollback. It is
  skipped unless a target is configured, which keeps local and forked runs
  self-contained.

## Running the control-plane suite

```bash
composer install
php artisan test --testsuite="Control plane"
# or: composer test
```

CI additionally boots the application the way production does — migrations,
`config:cache`, `route:cache`, `view:cache`, `route:list`, `control:reap
--dry-run` — because most deployment failures show up at that stage rather than
in unit tests.

## Running the real-SSH suite

Requirements:

- an SSH server reachable from the machine running PHPUnit;
- a user with key-based login and SFTP (loopback is fine);
- the server's SHA256 host-key fingerprint;
- outbound HTTPS access to github.com for the clone of `octocat/Hello-World`.

Environment variables:

| Variable | Meaning |
|---|---|
| `CONTROL_SSH_TEST_TARGET` | `host:port`; **presence alone enables the suite** |
| `CONTROL_SSH_TEST_USER` | login user (default `runner`); must own the project directory |
| `CONTROL_SSH_TEST_KEY` | PEM/OpenSSH private key, newlines as real newlines |
| `CONTROL_SSH_TEST_FINGERPRINT` | `SHA256:...` host-key fingerprint of the test server |

```bash
export CONTROL_SSH_TEST_TARGET=127.0.0.1:2222
export CONTROL_SSH_TEST_USER="$(id -un)"
export CONTROL_SSH_TEST_KEY="$(cat ~/.ssh/id_ed25519)"
export CONTROL_SSH_TEST_FINGERPRINT="$(ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256 | awk '{print $2}')"

php vendor/bin/phpunit --testsuite Integration
```

The test enables `control.allow_private_targets` for its own process so that the
loopback target is accepted; production behaviour (rejecting private and
reserved destinations) is asserted separately in
`tests/Feature/PublicHttpGuardTest.php`. Test directories
(`/home/<user>/deploy-tests-*`) are removed in `tearDown`.

## Load-time fatal guards

`php tests/suite-hygiene.php` runs before the suite. It reflects the final
methods inherited from PHPUnit's and Laravel's `TestCase` and rejects any test
class that redeclares one (for example a helper named `run()`), because that is
a link-time fatal: the whole PHP process dies, no test runs, the JUnit report
stays empty and CI only shows exit code 255. It also enforces PSR-4
name/path consistency so PHPUnit can always load every test file.

## CI

`.github/workflows/tests.yml` runs both jobs on every push:

1. **php** — lint-level smoke checks, production-style boot, then the
   control-plane suite. `.github/workflows/lockfile.yml` is a manual
   `workflow_dispatch` job that resolves dependencies and commits
   `composer.lock`, because the development sandbox has no access to
   packagist.
2. **integration** — installs `openssh-server` on the runner, generates a key,
   starts `sshd` on port 2222, exports the variables above and runs the
   integration suite for real.

Failures are republished as annotations on the check run by
`tests/report-ci.php`, so a result is readable without downloading job logs
(useful when the log host is unreachable).
