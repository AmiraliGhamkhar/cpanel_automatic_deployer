<?php
namespace App\Services\Security;
final class Redactor
{
    public function redact(string $text, array $secrets = []): string
    {
        usort(
            $secrets,
            fn($a, $b) => strlen((string) $b) <=> strlen((string) $a),
        );
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== "") {
                $text = str_replace(
                    [$secret, rawurlencode($secret), base64_encode($secret)],
                    "[REDACTED]",
                    $text,
                );
            }
        }
        $text = preg_replace(
            "/-----BEGIN [^-]*PRIVATE KEY-----.*?-----END [^-]*PRIVATE KEY-----/s",
            "[REDACTED KEY]",
            $text,
        );
        $text = preg_replace(
            "~(https?://)[^\s/@:]+:[^\s/@]+@~i",
            '$1[REDACTED]@',
            $text,
        );
        return preg_replace(
            '/((?:password|token|secret|api[_-]?key|database_url|authorization)\s*[=:]\s*)[^\r\n]+/i',
            '$1[REDACTED]',
            $text,
        );
    }
}
