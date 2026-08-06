<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns\SignsCybersourceRequests;

/**
 * Expose the protected signing trait for assertions.
 */
function signatureProbe(): object
{
    return new class
    {
        use SignsCybersourceRequests;

        /**
         * @param  array{host: string, merchant_id: string, api_key_id: string, shared_secret: string}  $credentials
         * @return array<string, string>
         */
        public function headers(string $method, string $path, array $credentials, ?string $payload, string $date): array
        {
            return $this->buildSignatureHeaders($method, $path, $credentials, $payload, $date);
        }
    };
}

/**
 * @return array{host: string, merchant_id: string, api_key_id: string, shared_secret: string}
 */
function signingCredentials(): array
{
    return [
        'host' => 'apitest.cybersource.com',
        'merchant_id' => 'test_merchant',
        'api_key_id' => 'test_key',
        'shared_secret' => base64_encode('test_secret'),
    ];
}

it('produces the golden CyberSource HMAC signature for fixed inputs', function (): void {
    $headers = signatureProbe()->headers(
        'POST',
        '/pts/v2/payments',
        signingCredentials(),
        '{"amount":"100.00"}',
        'Mon, 05 Aug 2026 12:00:00 GMT',
    );

    expect($headers['Digest'])->toBe('SHA-256=golcmw671Hk3CORs9QKq6YLRqtabWc7MtrEy3UOAtwY=')
        ->and($headers['Signature'])->toContain('signature="N2H1n5b4jKg9Vjs4i5aArHmhoX/vYskMHv2OZuYD9H0="')
        ->and($headers['Signature'])->toContain('keyid="test_key"')
        ->and($headers['Signature'])->toContain('algorithm="HmacSHA256"')
        ->and($headers['Signature'])->toContain('headers="(request-target) host digest v-c-date v-c-merchant-id"')
        ->and($headers['v-c-merchant-id'])->toBe('test_merchant')
        ->and($headers['v-c-date'])->toBe('Mon, 05 Aug 2026 12:00:00 GMT');
});

it('omits the digest and its signed header for GET requests', function (): void {
    $headers = signatureProbe()->headers(
        'GET',
        '/tss/v2/transactions/123',
        signingCredentials(),
        null,
        'Mon, 05 Aug 2026 12:00:00 GMT',
    );

    expect($headers)->not->toHaveKey('Digest')
        ->and($headers['Signature'])->toContain('headers="(request-target) host v-c-date v-c-merchant-id"')
        ->and($headers['Accept'])->toBe('application/hal+json;charset=utf-8');
});

it('changes the signature when the payload changes', function (): void {
    $first = signatureProbe()->headers('POST', '/pts/v2/payments', signingCredentials(), '{"a":1}', 'Mon, 05 Aug 2026 12:00:00 GMT');
    $second = signatureProbe()->headers('POST', '/pts/v2/payments', signingCredentials(), '{"a":2}', 'Mon, 05 Aug 2026 12:00:00 GMT');

    expect($first['Signature'])->not->toBe($second['Signature']);
});
