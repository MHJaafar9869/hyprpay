<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Contract\CredentialResolver;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;

/**
 * A recording CredentialResolver that returns fixed credentials and notes when it ran.
 */
function recordingResolver(GatewayCredentials $credentials): CredentialResolver
{
    return new class($credentials) implements CredentialResolver
    {
        public bool $called = false;

        public function __construct(private readonly GatewayCredentials $credentials) {}

        public function resolve(GatewayName $gateway): GatewayCredentials
        {
            $this->called = true;

            return $this->credentials;
        }
    };
}

/**
 * @param  array<string, mixed>  $overrides
 */
function testCredentials(array $overrides = []): GatewayCredentials
{
    return new GatewayCredentials(
        host: $overrides['host'] ?? 'apitest.cybersource.com',
        merchantId: $overrides['merchantId'] ?? 'test_merchant',
        apiKeyId: $overrides['apiKeyId'] ?? 'test_key',
        sharedSecret: $overrides['sharedSecret'] ?? base64_encode('test_secret'),
        testMode: true,
        webhookSecret: $overrides['webhookSecret'] ?? base64_encode('webhook_secret'),
    );
}

/**
 * Build an unsigned-payload JWT (header.payload.signature) for token-parsing tests.
 *
 * @param  array<string, mixed>  $claims
 */
function fakeJwt(array $claims): string
{
    $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

    return $encode('{"alg":"none"}').'.'.$encode((string) json_encode($claims)).'.signature';
}

/**
 * Load the shared PayLink golden-signature fixtures (cross-SDK parity vectors).
 *
 * @return array{hashToken: string, cases: array<int, array<string, mixed>>}
 */
function goldenFixtures(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/fixtures/golden-signatures.json'), true);
}
