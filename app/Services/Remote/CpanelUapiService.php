<?php
namespace App\Services\Remote;
use App\Models\Server;
use App\Services\Security\{PublicHttp, Input};
class CpanelUapiService implements CpanelServiceInterface
{
    public function __construct(private PublicHttp $http) {}
    public function inspect(Server $server, string $feature): array
    {
        $endpoints = [
            "account" => "Variables/get_user_information",
            "domains" => "DomainInfo/list_domains",
            "disk" => "Quota/get_quota_info",
            "databases" => "Mysql/list_databases",
            "git" => "VersionControl/retrieve",
            "applications" => "PassengerApps/list_applications",
        ];
        if (!isset($endpoints[$feature])) {
            throw new \InvalidArgumentException("Unsupported UAPI feature.");
        }
        if (
            !$server->enabled ||
            !in_array($server->connection_mode, [
                "cpanel_api",
                "cpanel_api_and_ssh",
            ]) ||
            !$server->cpanel_api_token
        ) {
            return [
                "status" => "unavailable",
                "message" => "API access not configured.",
            ];
        }
        Input::username($server->cpanel_username);
        try {
            $response = $this->http->get(
                "https://" .
                    $server->hostname .
                    ":" .
                    $server->port .
                    "/execute/" .
                    $endpoints[$feature],
                [
                    "Authorization" =>
                        "cpanel " .
                        $server->cpanel_username .
                        ":" .
                        $server->cpanel_api_token,
                ],
            );
            if (in_array($response->status(), [401, 403])) {
                return [
                    "status" => "unavailable",
                    "message" =>
                        "API token rejected or insufficient permissions.",
                ];
            }
            if ($response->status() === 429) {
                return [
                    "status" => "unknown",
                    "message" => "API rate limit reached. Retry later.",
                ];
            }
            if (
                !$response->successful() ||
                $response->json("result.status") !== 1
            ) {
                return [
                    "status" => "unavailable",
                    "message" => "Endpoint unsupported, restricted or failed.",
                ];
            }
            // API payloads can contain sensitive account data; the browser receives capability status only.
            return [
                "status" => "available",
                "message" => "UAPI endpoint responded successfully.",
            ];
        } catch (\Throwable) {
            return [
                "status" => "unknown",
                "message" =>
                    "Connection failed. Verify DNS, TLS, port and provider API access.",
            ];
        }
    }
}
