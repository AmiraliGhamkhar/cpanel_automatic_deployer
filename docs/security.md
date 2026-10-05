# Security model

This is an administrator-only personal control plane, not a multi-tenant SaaS. Anyone with admin access can deploy executable code as the managed hosting account. Put the panel behind HTTPS and preferably an additional VPN/access gateway. Native MFA is not implemented; add an identity gateway with MFA before public production exposure.

## Controls

- Passwords hashed using Laravel; private CLI admin creation; no registration/default account. Filament login throttles attempts. Database sessions expire after 30 minutes by default and on browser close; cookies are HTTP-only, secure and SameSite=strict. CSRF and persistent Livewire authentication apply to panel actions. Authenticated POST traffic is bounded.
- Resource policies and service/worker gates require an administrator. User identity is checked again in jobs. Deployment, rollback, restart, backup and environment changes require a confirmation in the UI; deploy/rollback/environment services also validate a confirmation flag.
- Laravel encrypted casts store API tokens, SSH private keys and environment arrays under APP_KEY. Hidden serialization and stripped form hydration prevent saved secrets reaching the browser. Submitted secrets necessarily exist briefly in the authenticated browser while entering them; no reveal action is provided.
- SSH authenticates only after a pinned host key matches. TLS verification is never disabled. Network connections use validated public DNS results; HTTP and SSH pin the chosen address. HTTP redirects and embedded credentials are refused, timeouts/retries/body sizes bounded. Internal/private-network targets are intentionally unsupported.
- Remote commands are predefined templates; parameters are validated and shell-quoted. No shell console or arbitrary build/restart field. Target paths are account-scoped, nonoverlapping and marker-owned; symlinked ancestors are rejected.
- Raw remote output and cPanel payloads do not enter normal logs or the UI. Structured deployment events use safe first-party text and a redactor. Known exception output is suppressed in jobs. Do not enable request/SQL/HTTP-body debug tools, Telescope or verbose production logging around secret workflows.
- Git metadata is removed from newly cloned releases before activation. Static sites do not accept managed environment secrets. Provider web roots must deny dotfiles and Laravel must expose only `public`; repository authors remain responsible for not publishing source secrets.
- New remote secret files are empty when created, chmod 0600 before content is written, then atomically renamed. Shared directories and backup archives are private. SSH output buffers and HTTP downloads are bounded.
- Audit records contain actor, action, project/server, time, IP (request origin when available) and result, never environment values/credentials. Audit/history deletion has no UI action. These are operational logs, not cryptographically tamper-proof records against a database administrator.

## Trust boundaries / limitations

Allowlisted Git URLs do not make repository code safe. Composer/npm/Python installation can execute scripts. A malicious repository or dependency can access the target account's other projects and secrets. Use isolated hosting accounts for untrusted code; this product is not a sandbox. A compromised hosting account can also race filesystem operations. SSH/SFTP checks assume the target account itself is trusted.

Rollback changes files only. Schema changes, shared environment, uploads and external side effects remain. Failed health verification may occur after activation. There is no automatic promise of zero downtime or state recovery.

Backups may contain cached secrets even when .env is excluded. Target env files are plaintext for the application under restricted permissions; control-plane DB encryption does not protect a compromised target or compromised application process. APP_KEY and database backups must be protected separately; encrypted columns do not protect against compromise of the control-plane process holding that key.

HTTPS health checks reject internal addresses; unusual provider networks, aliases outside /home, nonstandard API ports and providers lacking symlinks/POSIX rename require a reviewed adapter. Do not remove protections casually to make one provider work.

## Pre-production review

Resolve and pin Composer dependencies; run PHPUnit/CI, dependency audit and browser/Livewire tests; verify policies on every resource/action; inspect server secret edit payloads; rehearse concurrent requests, worker timeouts and post-activation health failures against a disposable target. Verify the recovery process and provider database backups. PHPUnit HTTP feature and safety tests run in GitHub Actions; full browser/Livewire interaction and live-provider integration verification remain outstanding.
