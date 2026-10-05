<?php
namespace App\Services;
use App\Models\Server;
use App\Services\Remote\{CpanelServiceInterface, SshServiceInterface, Command};
class CapabilityDetector
{
    public function __construct(
        private CpanelServiceInterface $cpanel,
        private SshServiceInterface $ssh,
    ) {}
    public function check(Server $server): array
    {
        $result = [];
        foreach (
            ["account", "domains", "disk", "databases", "git", "applications"]
            as $feature
        ) {
            $result["cpanel_" . $feature] = $this->cpanel->inspect(
                $server,
                $feature,
            );
        }
        $names = [
            "ssh",
            "sftp",
            "git",
            "php",
            "composer",
            "python",
            "pip",
            "node",
            "npm",
            "cron",
            "disk",
            "timeout",
            "tar",
            "symlink",
            "passenger",
            "mysqldump",
        ];
        foreach ($names as $name) {
            $result[$name] = [
                "status" => "unknown",
                "message" => "SSH not available or not configured.",
            ];
        }
        if (in_array($server->connection_mode, ["ssh", "cpanel_api_and_ssh"])) {
            try {
                $this->ssh->connect($server);
                foreach (["ssh", "sftp"] as $name) {
                    $result[$name] = [
                        "status" => "available",
                        "message" => "Authenticated with verified host key.",
                    ];
                }
                foreach (array_diff($names, ["ssh", "sftp"]) as $name) {
                    try {
                        $output = $this->ssh->run(Command::probe($name));
                        $message =
                            "Probe succeeded; provider restrictions may still apply.";
                        if (
                            in_array($name, [
                                "php",
                                "composer",
                                "python",
                                "pip",
                                "node",
                                "npm",
                            ]) &&
                            preg_match(
                                "/\b[0-9]{1,3}\.[0-9]{1,3}(?:\.[0-9]{1,3})?\b/",
                                $output,
                                $match,
                            )
                        ) {
                            $message = "Detected version " . $match[0] . ".";
                        }
                        if ($name === "disk") {
                            $rows = preg_split('/\r?\n/', trim($output));
                            $parts = preg_split("/\s+/", end($rows));
                            if (isset($parts[3]) && ctype_digit($parts[3])) {
                                $message =
                                    "Available disk space: " .
                                    $parts[3] .
                                    " KiB.";
                            }
                        }
                        $result[$name] = [
                            "status" => "available",
                            "message" => $message,
                        ];
                    } catch (\Throwable) {
                        $result[$name] = [
                            "status" => "unavailable",
                            "message" =>
                                "Unavailable or restricted by provider.",
                        ];
                    }
                }
            } catch (\Throwable) {
                $result["ssh"] = [
                    "status" => "unavailable",
                    "message" =>
                        "Check network, key, account and pinned fingerprint.",
                ];
            }
        }
        $online =
            $result["ssh"]["status"] === "available" ||
            $result["cpanel_account"]["status"] === "available";
        \Illuminate\Support\Facades\DB::transaction(function () use (
            $server,
            $result,
            $online,
        ) {
            $current = Server::lockForUpdate()->findOrFail($server->id);
            // Never attach old probe results to credentials/settings changed while the job ran.
            foreach (
                [
                    "hostname",
                    "port",
                    "connection_mode",
                    "enabled",
                    "cpanel_username",
                    "cpanel_api_token",
                    "ssh_username",
                    "ssh_port",
                    "ssh_private_key",
                    "ssh_host_fingerprint",
                ]
                as $field
            ) {
                if (
                    (string) $current->getRawOriginal($field) !==
                    (string) $server->getRawOriginal($field)
                ) {
                    return;
                }
            }
            $current->update([
                "capabilities" => $result,
                "last_health_check_at" => now(),
                "status" => $online ? "online" : "offline",
            ]);
        });
        return $result;
    }
}
