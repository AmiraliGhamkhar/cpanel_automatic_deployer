<?php

namespace App\Services\Remote;

use App\Models\Server;
use App\Services\Security\{Input, PublicHttp};

/**
 * cPanel UAPI adapter.
 *
 * Every call is explicit, parameter-validated and returns a normalized
 * envelope. Raw UAPI payloads are never returned to the browser: callers get a
 * status, a sanitized message and, when they ask for it, a small set of
 * whitelisted fields.
 */
class CpanelUapiService implements CpanelServiceInterface
{
    /** Endpoints used for capability inspection. */
    private const INSPECTION = [
        "account" => "Variables/get_user_information",
        "domains" => "DomainInfo/list_domains",
        "disk" => "Quota/get_quota_info",
        "databases" => "Mysql/list_databases",
        "applications" => "PassengerApps/list_applications",
    ];

    /**
     * Read-only probes that tell us whether Git Version Control is reachable.
     * Providers differ in which of these exist, so both are tried.
     */
    private const GIT_PROBES = [
        "VersionControl/list",
        "VersionControl/retrieve",
    ];

    public function __construct(private PublicHttp $http) {}

    public function inspect(Server $server, string $feature): array
    {
        if ($feature === "git") {
            return $this->inspectGit($server);
        }
        if (!isset(self::INSPECTION[$feature])) {
            throw new \InvalidArgumentException("Unsupported UAPI feature.");
        }
        return $this->call($server, self::INSPECTION[$feature]);
    }

    /** Git capability: which read-only VersionControl probe answers. */
    private function inspectGit(Server $server): array
    {
        $last = [
            "status" => "unavailable",
            "message" => "API access not configured.",
        ];
        foreach (self::GIT_PROBES as $endpoint) {
            $result = $this->call($server, $endpoint);
            if ($result["status"] === "available") {
                $result["message"] =
                    "Git Version Control UAPI responded (" .
                    $endpoint .
                    ").";
                return $result;
            }
            if ($result["status"] === "unknown") {
                return $result;
            }
            $last = $result;
        }
        return $last;
    }

    /**
     * Perform one UAPI call.
     *
     * @param array<string,string|int> $params
     * @param list<string>|null $fields whitelist of response fields to return
     * @return array{status:string,message:string,data?:array}
     */
    public function call(
        Server $server,
        string $endpoint,
        array $params = [],
        ?array $fields = null,
    ): array {
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
        if (
            !preg_match(
                "~\\A[A-Za-z][A-Za-z0-9_]*/(?:[A-Za-z][A-Za-z0-9_]*)\.?\\z~",
                $endpoint,
            )
        ) {
            throw new \InvalidArgumentException("Unsupported UAPI endpoint.");
        }
        foreach ($params as $key => $value) {
            if (
                !preg_match("/\\A[A-Za-z][A-Za-z0-9_]*\\z/D", (string) $key) ||
                !is_scalar($value) ||
                preg_match('/[\\x00-\\x1f\\x7f]/', (string) $value)
            ) {
                throw new \InvalidArgumentException("Unsupported UAPI parameter.");
            }
        }

        Input::username($server->cpanel_username);
        try {
            $response = $this->http->get(
                "https://" .
                    $server->hostname .
                    ":" .
                    $server->port .
                    "/execute/" .
                    $endpoint,
                [
                    "Authorization" =>
                        "cpanel " .
                        $server->cpanel_username .
                        ":" .
                        $server->cpanel_api_token,
                ],
                $params,
            );
        } catch (\Throwable) {
            return [
                "status" => "unknown",
                "message" =>
                    "Connection failed. Verify DNS, TLS, port and provider API access.",
            ];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [
                "status" => "unavailable",
                "message" => "API token rejected or insufficient permissions.",
            ];
        }
        if ($response->status() === 429) {
            return [
                "status" => "unknown",
                "message" => "API rate limit reached. Retry later.",
            ];
        }
        $json = $response->json();
        if (!$response->successful() || ($json["result"]["status"] ?? null) !== 1) {
            return [
                "status" => "unavailable",
                "message" => $this->providerMessage($json),
            ];
        }

        $result = [
            "status" => "available",
            "message" => "UAPI endpoint responded successfully.",
        ];
        if ($fields !== null) {
            $data = $json["result"]["data"] ?? [];
            $result["data"] = is_array($data)
                ? array_intersect_key($data, array_flip($fields))
                : [];
        }
        return $result;
    }

    /**
     * Provider errors are useful for diagnosis but may echo the request, so the
     * message is trimmed to a single sanitized line.
     */
    private function providerMessage(mixed $json): string
    {
        $errors = $json["result"]["errors"] ?? null;
        $message = is_array($errors) ? implode("; ", array_map("strval", $errors)) : "";
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? "");
        if ($message === "") {
            return "Endpoint unsupported, restricted or failed.";
        }
        return "Provider response: " . mb_substr($message, 0, 300);
    }
}
