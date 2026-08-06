<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\Paylink\PaylinkEndpoint;
use Hyprpay\Payments\Infrastructure\Gateway\Paylink\PaylinkSignature;
use Hyprpay\Payments\Infrastructure\Gateway\Paylink\PaylinkSignedBody;

/**
 * Map a golden-fixture endpoint name to the driver's endpoint, or null when the
 * driver does not implement it (VCC / card-token / recurring flows).
 */
function paylinkEndpointFor(string $name): ?PaylinkEndpoint
{
    return match ($name) {
        'INVOICE_CREATE' => PaylinkEndpoint::InvoiceCreate,
        'PAYMENT_VOID' => PaylinkEndpoint::Void,
        'PAYMENT_REFUND' => PaylinkEndpoint::Refund,
        'PAYMENT_SETTLE' => PaylinkEndpoint::Settle,
        'PAYMENT_REVERSE_AUTHORIZATION' => PaylinkEndpoint::ReverseAuthorization,
        'PAYMENT_CHECK_STATUS' => PaylinkEndpoint::CheckStatus,
        default => null,
    };
}

/**
 * Convert a fixture's camelCase input keys to the driver's snake_case wire names.
 *
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function paylinkSnakeKeys(array $input): array
{
    $out = [];

    foreach ($input as $key => $value) {
        $out[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key))] = $value;
    }

    return $out;
}

it('matches the golden HMAC signature for every shared fixture case', function (): void {
    $fixtures = goldenFixtures();

    foreach ($fixtures['cases'] as $case) {
        $hashToken = $case['hashToken'] ?? $fixtures['hashToken'];

        expect(PaylinkSignature::build($case['values'], $hashToken))->toBe($case['expected']);
    }
});

it('builds the signed body in the correct field order for every supported endpoint', function (): void {
    $fixtures = goldenFixtures();
    $covered = 0;

    foreach ($fixtures['cases'] as $case) {
        $endpoint = paylinkEndpointFor($case['endpoint']);

        if (! $endpoint instanceof PaylinkEndpoint) {
            continue;
        }

        $body = PaylinkSignedBody::build(
            $endpoint,
            paylinkSnakeKeys($case['input']),
            'PUBLIC_TOKEN',
            $case['hashToken'] ?? $fixtures['hashToken'],
        );

        expect($body['signature'])->toBe($case['expected'])
            ->and($body['token'])->toBe('PUBLIC_TOKEN');

        $covered++;
    }

    expect($covered)->toBeGreaterThanOrEqual(6);
});

it('coerces values the way the server signs them', function (): void {
    expect(PaylinkSignature::coerce(null))->toBe('')
        ->and(PaylinkSignature::coerce(true))->toBe('1')
        ->and(PaylinkSignature::coerce(false))->toBe('0')
        ->and(PaylinkSignature::coerce(250))->toBe('250')
        ->and(PaylinkSignature::coerce(250.0))->toBe('250')
        ->and(PaylinkSignature::coerce('100.00'))->toBe('100.00');
});
