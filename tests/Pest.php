<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
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

/**
 * A per-process RSA test signing key (PEM) used to sign fixture result JWTs.
 */
function testResultJwtPrivateKey(): string
{
    static $pem = null;

    if ($pem === null) {
        openssl_pkey_export(
            openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]),
            $pem,
        );
    }

    return (string) $pem;
}

/**
 * Derive the public JWK for a signing key, shaped like CyberSource's capture-context flx.jwk.
 *
 * @param  string|null  $privateKeyPem  Signing key to derive from; defaults to the committed test key.
 * @return array<string, string>
 */
function testResultJwtPublicJwk(string $kid = 'test-kid', ?string $privateKeyPem = null): array
{
    $key = openssl_pkey_get_private($privateKeyPem ?? testResultJwtPrivateKey());
    $details = openssl_pkey_get_details($key);
    $b64url = static fn (string $binary): string => rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');

    return [
        'kty' => 'RSA',
        'use' => 'sig',
        'alg' => 'RS256',
        'kid' => $kid,
        'n' => $b64url((string) $details['rsa']['n']),
        'e' => $b64url((string) $details['rsa']['e']),
    ];
}

/**
 * Sign a set of claims into an RS256 result JWT with the given (or default test) key.
 *
 * @param  array<string, mixed>  $claims
 */
function signedResultJwt(array $claims, string $kid = 'test-kid', ?string $privateKeyPem = null): string
{
    return JWT::encode($claims, $privateKeyPem ?? testResultJwtPrivateKey(), 'RS256', $kid);
}

/**
 * Build a capture-context JWT embedding the given verification JWK at flx.jwk.
 *
 * @param  array<string, string>|null  $jwk  The public JWK to embed; defaults to the test key's JWK.
 */
function captureContextWithJwk(?array $jwk = null): string
{
    return fakeJwt(['flx' => ['jwk' => $jwk ?? testResultJwtPublicJwk()]]);
}
