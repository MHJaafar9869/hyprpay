<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Domain\ValueObject\Money;

it('round-trips a fully populated record through its array form', function (): void {
    $record = PaymentActivityRecord::make(
        'PaymentCharged',
        GatewayName::CybersourceUnifiedCheckout,
        PaymentStatus::Captured,
        true,
        'ORD-1',
        'txn_1',
        'AUTH-1',
        Money::minor(2599, 'USD'),
        '2026-08-24T10:00:00+00:00',
    );

    $restored = PaymentActivityRecord::fromArray($record->toArray());

    expect($restored)->not->toBeNull()
        ->and($restored->operation)->toBe('PaymentCharged')
        ->and($restored->gateway)->toBe(GatewayName::CybersourceUnifiedCheckout)
        ->and($restored->status)->toBe(PaymentStatus::Captured)
        ->and($restored->success)->toBeTrue()
        ->and($restored->orderReference)->toBe('ORD-1')
        ->and($restored->transactionId)->toBe('txn_1')
        ->and($restored->reference)->toBe('AUTH-1')
        ->and($restored->amountMinor)->toBe(2599)
        ->and($restored->currency)->toBe('USD')
        ->and($restored->scale)->toBe(2)
        ->and($restored->recordedAt)->toBe('2026-08-24T10:00:00+00:00');
});

it('preserves a null status, outcome, and amount', function (): void {
    $record = PaymentActivityRecord::make(
        'CheckoutSessionCreated', GatewayName::Fawry, null, null, 'ORD-2', 'REF-9', null, null, '2026-08-24T10:00:00+00:00',
    );

    $restored = PaymentActivityRecord::fromArray($record->toArray());

    expect($restored)->not->toBeNull()
        ->and($restored->status)->toBeNull()
        ->and($restored->success)->toBeNull()
        ->and($restored->amountMinor)->toBeNull()
        ->and($restored->currency)->toBeNull()
        ->and($restored->scale)->toBeNull();
});

it('discards a row with an unknown gateway', function (): void {
    expect(PaymentActivityRecord::fromArray(['gateway' => 'not_a_gateway', 'operation' => 'PaymentCharged']))->toBeNull();
});
