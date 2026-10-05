<?php
namespace App\Services\Remote;
use App\Models\Server;
use App\Services\Security\{Input, PublicHttp};
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;
class SshService implements SshServiceInterface
{
    private SFTP $client;
    public function __construct(private PublicHttp $network) {}
    public function connect(Server $server): void
    {
        if (
            !$server->enabled ||
            !in_array($server->connection_mode, ["ssh", "cpanel_api_and_ssh"])
        ) {
            throw new RuntimeException("SSH is disabled for this server.");
        }
        Input::username($server->ssh_username ?? "");
        if (
            !preg_match(
                "/\ASHA256:[A-Za-z0-9+\/]{43}\z/D",
                $server->ssh_host_fingerprint ?? "",
            )
        ) {
            throw new RuntimeException(
                "A verified SHA256 SSH host fingerprint is required.",
            );
        }
        try {
            $this->client = new SFTP(
                $this->network->resolve($server->hostname),
                $server->ssh_port,
                10,
            );
            $key = $this->client->getServerPublicHostKey();
            if (
                !$key ||
                !hash_equals(
                    $server->ssh_host_fingerprint,
                    "SHA256:" .
                        PublicKeyLoader::load($key)->getFingerprint("sha256"),
                )
            ) {
                throw new RuntimeException();
            }
            if (
                !$this->client->login(
                    $server->ssh_username,
                    PublicKeyLoader::loadPrivateKey(
                        $server->ssh_private_key ?? "",
                    ),
                )
            ) {
                throw new RuntimeException();
            }
            $this->client->setTimeout(320);
        } catch (\Throwable) {
            throw new RuntimeException(
                "SSH failed: verify connectivity, key authentication and the pinned host fingerprint.",
            );
        }
    }
    public function run(Command $command): string
    {
        try {
            $output = "";
            $result = $this->client->exec(
                "umask 077; ( " . $command->shell . " ) 2>&1",
                function ($chunk) use (&$output) {
                    $remaining = 65536 - strlen($output);
                    if ($remaining > 0) {
                        $output .= substr($chunk, 0, $remaining);
                    }
                },
            );
            if (
                $result === false ||
                $this->client->isTimeout() ||
                $this->client->getExitStatus() !== 0
            ) {
                throw new RuntimeException();
            }
            return substr($output, 0, 65536);
        } catch (\Throwable) {
            throw new RuntimeException(
                "Remote operation failed or timed out. Inspect the runtime, permissions and provider limits on the host. Raw output was withheld to protect secrets.",
            );
        }
    }
    public function exists(string $path): bool
    {
        return $this->client->file_exists($path);
    }
    public function upload(string $path, string $content): void
    {
        // Never follow a pre-existing link when writing secrets.
        $temp = $path . ".control-" . bin2hex(random_bytes(12));
        if (
            $this->client->is_link($path) ||
            !$this->client->put($temp, "") ||
            !$this->client->chmod(0600, $temp) ||
            !$this->client->put($temp, $content) ||
            !$this->client->posix_rename($temp, $path)
        ) {
            $this->client->delete($temp);
            throw new RuntimeException(
                "Secure atomic SFTP upload failed; POSIX rename support is required.",
            );
        }
    }
    public function read(string $path): string
    {
        $stat = $this->client->stat($path);
        if (!$stat || ($stat["size"] ?? 0) > 65536) {
            throw new RuntimeException("Remote file is absent or too large.");
        }
        $value = $this->client->get($path);
        if ($value === false) {
            throw new RuntimeException("Remote read failed.");
        }
        return $value;
    }
}
