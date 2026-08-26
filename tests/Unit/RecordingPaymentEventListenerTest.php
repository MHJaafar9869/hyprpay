<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\Event\InstrumentVaulted;
use Hyprpay\Payments\Domain\Event\PaymentCharged;
use Hyprpay\Payments\Domain\Event\PaymentRefunded;
use Hyprpay\Payments\Domain\Event\WalletCharged;
use Hyprpay\Payments\Domain\Event\WebhookReceived;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Events\RecordingPaymentEventListener;
use Hyprpay\Payments\Tests\Support\InMemoryPaymentActivity;
use Illuminate\Support\Carbon;

it('records a charge with its outcome and amount', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new PaymentCharged(
        GatewayName::CybersourceUnifiedCheckout,
        'ORD-1',
        Money::minor(2599, 'USD'),
        new PaymentResult(true, PaymentStatus::Captured, 'txn_1'),
    ));

    $record = $activity->records[0];

    expect($record->operation)->toBe('PaymentCharged')
        ->and($record->gateway)->toBe(GatewayName::CybersourceUnifiedCheckout)
        ->and($record->status)->toBe(PaymentStatus::Captured)
        ->and($record->success)->toBeTrue()
        ->and($record->orderReference)->toBe('ORD-1')
        ->and($record->transactionId)->toBe('txn_1')
        ->and($record->amountMinor)->toBe(2599)
        ->and($record->currency)->toBe('USD');
});

it('records a refund with the refund id as the transaction and the captured id as reference', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new PaymentRefunded(
        GatewayName::Paytabs,
        'CAP-1',
        'ORD-1',
        Money::minor(2500, 'SAR'),
        new RefundResult(true, PaymentStatus::Refunded, 'REF-1'),
    ));

    $record = $activity->records[0];

    expect($record->operation)->toBe('PaymentRefunded')
        ->and($record->status)->toBe(PaymentStatus::Refunded)
        ->and($record->transactionId)->toBe('REF-1')
        ->and($record->reference)->toBe('CAP-1');
});

it('records a wallet charge keyed by the wallet type', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new WalletCharged(
        GatewayName::CybersourceUnifiedCheckout,
        WalletType::ApplePay,
        'ORD-9',
        Money::minor(500, 'USD'),
        new PaymentResult(true, PaymentStatus::Authorized, 'txn_w'),
    ));

    expect($activity->records[0]->reference)->toBe(WalletType::ApplePay->value)
        ->and($activity->records[0]->status)->toBe(PaymentStatus::Authorized);
});

it('records a webhook using the verified flag as the outcome', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new WebhookReceived(
        GatewayName::PayPal,
        new WebhookEvent(true, 'PAYMENT.CAPTURE.COMPLETED', 'WT-1', PaymentStatus::Captured),
    ));

    $record = $activity->records[0];

    expect($record->success)->toBeTrue()
        ->and($record->transactionId)->toBe('WT-1')
        ->and($record->reference)->toBe('PAYMENT.CAPTURE.COMPLETED')
        ->and($record->status)->toBe(PaymentStatus::Captured);
});

it('records a vaulting keyed by the customer reference', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new InstrumentVaulted(
        GatewayName::CybersourceUnifiedCheckout,
        'CUST-7',
        new VaultedInstrument(true, paymentInstrumentId: 'PI-1'),
    ));

    $record = $activity->records[0];

    expect($record->operation)->toBe('InstrumentVaulted')
        ->and($record->transactionId)->toBe('PI-1')
        ->and($record->reference)->toBe('CUST-7')
        ->and($record->status)->toBeNull()
        ->and($record->amountMinor)->toBeNull();
});

it('never stores the raw payload or card data', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new PaymentCharged(
        GatewayName::CybersourceUnifiedCheckout,
        'ORD-1',
        Money::minor(2599, 'USD'),
        new PaymentResult(true, PaymentStatus::Captured, 'txn_1', raw: ['pan' => '4111111111111111']),
    ));

    $stored = $activity->records[0]->toArray();

    expect($stored)->not->toHaveKey('raw')
        ->and(json_encode($stored))->not->toContain('4111111111111111');
});

it('stamps the record with the current time', function (): void {
    Carbon::setTestNow('2026-08-24T12:00:00+00:00');
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new PaymentCharged(
        GatewayName::Fawry, 'ORD-1', Money::minor(100, 'EGP'), new PaymentResult(true, PaymentStatus::Pending),
    ));

    expect($activity->records[0]->recordedAt)->toBe(Carbon::now()->toIso8601String());

    Carbon::setTestNow();
});
