<?php
namespace App\Services\Security;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class PublicHttp
{
    public function resolve(string $host): string
    {
        if (
            !preg_match("/\A[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}\z/D", $host) ||
            !str_contains($host, ".")
        ) {
            throw new RuntimeException("A public hostname is required.");
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_merge(
                array_column(dns_get_record($host, DNS_A) ?: [], "ip"),
                array_column(dns_get_record($host, DNS_AAAA) ?: [], "ipv6"),
            );
        if (!$ips) {
            throw new RuntimeException("Hostname could not be resolved.");
        }
        foreach ($ips as $ip) {
            if (
                !filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) ||
                str_starts_with($ip, "::ffff:")
            ) {
                throw new RuntimeException(
                    "Private or reserved network destinations are not permitted.",
                );
            }
        }
        return $ips[0];
    }
    public function get(string $url, array $headers = [], array $query = [])
    {
        $parts = parse_url($url);
        if (
            !$parts ||
            ($parts["scheme"] ?? "") !== "https" ||
            isset($parts["user"]) ||
            isset($parts["pass"]) ||
            isset($parts["fragment"])
        ) {
            throw new RuntimeException(
                "An HTTPS URL without embedded credentials is required.",
            );
        }
        $host = $parts["host"] ?? "";
        $port = $parts["port"] ?? 443;
        if (!in_array($port, [443, 2083], true)) {
            throw new RuntimeException("Unsupported HTTPS port.");
        }
        $ip = $this->resolve($host);
        try {
            return Http::withHeaders($headers)
                ->connectTimeout(8)
                ->timeout(20)
                ->withoutRedirecting()
                ->withOptions([
                    "verify" => true,
                    "proxy" => "",
                    "on_headers" => function ($response) {
                        if (
                            (int) $response->getHeaderLine("Content-Length") >
                            2097152
                        ) {
                            throw new RuntimeException(
                                "HTTP response too large.",
                            );
                        }
                    },
                    "progress" => function ($total, $downloaded) {
                        if ($downloaded > 2097152) {
                            throw new RuntimeException(
                                "HTTP response too large.",
                            );
                        }
                    },
                    "curl" => [
                        CURLOPT_RESOLVE => [
                            "{$host}:{$port}:" .
                            (str_contains($ip, ":") ? "[{$ip}]" : $ip),
                        ],
                    ],
                ])
                ->get($url, $query);
        } catch (\Throwable) {
            throw new RuntimeException(
                "HTTPS connection failed; verify DNS, TLS and firewall access.",
            );
        }
    }
}
