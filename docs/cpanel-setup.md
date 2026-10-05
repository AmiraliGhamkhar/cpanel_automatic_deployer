# cPanel setup

1. Use a provider-supplied public hostname whose certificate validates. Port 2083 is supported; do not disable TLS verification or use HTTP/2082.
2. In cPanel, create a scoped API token if your provider exposes API token management. Use only account-level permissions required for inspection. WHM/root credentials are never required.
3. Add the account username and token to Servers. Tokens are encrypted and write-only. Blank credential fields preserve a saved value; to revoke access, revoke the token at the provider and replace it or disable the server.
4. Pick the actual transport mode. `SSH releases` is the full-featured transport; `cPanel Git Version Control` deploys by pulling the tracked branch in the account and running its `.cpanel.yml`. API-only is valid for diagnostics but is not a deployable transport.
5. Confirm Test connection; keep the queue worker running. Diagnostics distinguish available, unavailable/restricted and unknown/error. Invalid/denied token, rate limiting, TLS failure and unsupported endpoint receive safe messages; optional failures do not abort the matrix.

Read-only UAPI routes: `Variables/get_user_information`, `DomainInfo/list_domains`, `Quota/get_quota_info`, `Mysql/list_databases`, `VersionControl/retrieve`, `PassengerApps/list_applications`. Provider support varies. The panel retains availability, not raw account payloads; listing databases does not establish database write access. Email management and app creation are not implemented.

For web apps, manually configure the domain document root to the release's `current` path (plus `public` for Laravel or the static build directory). Some providers refuse symlink document roots. Such hosts are currently unsupported for deployment; do not point this panel at an existing public_html tree as a workaround.

Node/Python apps must be registered through the provider's Application Manager/Passenger UI. Configure the runtime, app root and startup file there. Detection of Passenger endpoints/binaries is only preliminary; a real restart and HTTP health check decide deployment success. Cron-based app supervision, background bots and provider-specific launchers are not emulated.

Iranian hosts may have limited outbound GitHub/package-registry connectivity. Test access **from the target account** to GitHub, Packagist, PyPI and npm as applicable. The panel does not bypass network policy or silently install global packages. Missing runtimes or network access require provider assistance or a future artifact-upload transport.

## Git Version Control mode

Use this mode when the provider offers the cPanel API but not SSH keys. The panel
calls `VersionControl/update` and `VersionControlDeployment/create` with the server's
API token; it never writes into the repository directory itself.

1. In cPanel → Git Version Control, create the repository for the account and check
   out the branch you intend to deploy. Note the checked-out directory: it must match
   the project's remote path in the panel.
2. Add `.cpanel.yml` to the repository root with the tasks to run after each pull.
   Review it like a deploy script — it executes with the account's privileges.
3. Commit everything: cPanel silently skips `.cpanel.yml` tasks when the working tree
   has untracked files, and the deployment then "succeeds" without rebuilding.
4. In the project, select **cPanel Git Version Control (no releases, no rollback)**.
   The panel hides environment, restart, rollback and backup actions for it.

Verify the flow once by hand from cPanel's "Deploy HEAD Commit" button before trusting
the panel's health check. This mode cannot pin a commit, cannot roll back, and cannot
manage the application's `.env`; see docs/deployment.md for the full list of limits.
