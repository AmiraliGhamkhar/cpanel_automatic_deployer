<?php

namespace Tests\Feature;

use App\Services\Security\PublicHttp;
use Tests\TestCase;

/**
 * The control plane must never be usable as a probe into private networks.
 * The automated integration suite opts out explicitly for a loopback SSH
 * target; that exception must stay opt-in and off by default.
 */
class PublicHttpGuardTest extends TestCase
{
    public function test_private_and_reserved_destinations_are_rejected_by_default(): void
    {
        $this->assertFalse((bool) config("control.allow_private_targets"));
        $http = new PublicHttp();

        foreach (
            [
                "127.0.0.1",
                "::1",
                "::ffff:127.0.0.1",
                "10.1.2.3",
                "192.168.1.10",
                "169.254.169.254",
                "localhost",
            ]
            as $target
        ) {
            try {
                $http->resolve($target);
                $this->fail("Destination " . $target . " was accepted.");
            } catch (\RuntimeException $e) {
                $this->assertMatchesRegularExpression(
                    "/Private or reserved network destinations are not permitted|A public hostname is required/",
                    $e->getMessage(),
                );
            }
        }
    }

    public function test_loopback_is_allowed_only_when_the_test_flag_is_enabled(): void
    {
        config(["control.allow_private_targets" => true]);
        $this->assertSame("127.0.0.1", (new PublicHttp())->resolve("127.0.0.1"));
    }

    public function test_unsupported_schemes_credentials_ports_and_fragments_are_rejected(): void
    {
        $http = new PublicHttp();
        foreach (
            [
                "http://example.com/health",
                "https://user:pass@example.com/health",
                "https://example.com:8443/health",
                "https://example.com/health#fragment",
                "https:///health",
            ]
            as $url
        ) {
            try {
                $http->get($url);
                $this->fail("URL " . $url . " was accepted.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString(
                    "An HTTPS URL without embedded credentials is required",
                    $e->getMessage(),
                );
            }
        }
    }
}
