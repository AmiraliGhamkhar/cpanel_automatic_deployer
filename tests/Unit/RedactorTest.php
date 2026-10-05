<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Services\Security\Redactor;
class RedactorTest extends TestCase
{
    public function test_secrets_and_credentials_are_masked(): void
    {
        $value = new Redactor()->redact(
            "token=my-secret\nhttps://alice:password@example.com\n-----BEGIN OPENSSH PRIVATE KEY-----hidden-----END OPENSSH PRIVATE KEY-----\nplain-secret",
            ["plain-secret"],
        );
        foreach (
            ["my-secret", "password", "hidden", "plain-secret"]
            as $secret
        ) {
            $this->assertStringNotContainsString($secret, $value);
        }
    }
}
