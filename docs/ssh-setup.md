# SSH / SFTP setup

Generate a dedicated deployment key on a trusted administrator machine. Authorize the public key for the hosting account using the provider's supported SSH access flow. Do not grant root or sudo. Use a distinct key/account per host when practical; revoke keys at the host when no longer needed.

Obtain the server's SHA256 host-key fingerprint through an independently trusted provider channel. Format: `SHA256:` followed by 43 base64 characters, without trailing `=`. A blind `ssh-keyscan` result is **not** independent verification. The panel compares phpseclib's public-key fingerprint before authenticating; key changes fail closed. Update a fingerprint only after confirming a legitimate provider rotation.

Save the private key in the write-only form. This version accepts a usable, unencrypted private-key payload, encrypted immediately at rest by Laravel; passphrase/password authentication workflows are not implemented. The key never returns in a record response. Protect browser access and use HTTPS when initially entering it.

Requirements for deployment:

- SSH public-key authentication and SFTP on a publicly reachable hostname.
- `git`, user-level framework runtimes, `timeout`, `ln`, GNU-compatible `mv -T`, and normal POSIX filesystem semantics.
- SFTP POSIX rename for atomic protected environment replacement.
- Sufficient disk quota for source, dependencies, build output and retained releases.
- A dedicated empty `/home/<ssh_username>/...` project directory. Home aliases outside `/home` are not currently supported.

The initialization step rejects symlinked ancestors and nonempty unowned directories, then writes a project marker. Releases are named using unique deployment IDs. Existing releases are never recursively deleted. Configure retention manually only after confirming releases are inactive and are not needed for rollback.

SSH timeouts are bounded, install/Git/archive commands use target `timeout`, and job limits provide another bound. Provider process killers or unusual jailed shells may still interrupt operations. A command's executable presence is not a guarantee it can run under account limits. Do not enable an insecure fallback if host-key or SFTP checks fail.
