<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\Event\DccRateQuoted;
use Hyprpay\Payments\Domain\Event\InstrumentVaulted;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationEnrolled;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationSetUp;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationValidated;
use Hyprpay\Payments\Domain\Event\PaymentCharged;
use Hyprpay\Payments\Domain\Event\PaymentRefunded;
use Hyprpay\Payments\Domain\Event\WalletCharged;
use Hyprpay\Payments\Domain\Event\WebhookReceived;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\Result\PayerAuthSetupResult;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Events\RecordingPaymentEventListener;
use Hyprpay\Payments\Infrastructure\Http\ApiResponseRecorder;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;
use Hyprpay\Payments\Infrastructure\Http\RecordingHttpClient;
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

it('keeps the api-response list empty when no recorder is wired in', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new PaymentCharged(
        GatewayName::Fawry, 'ORD-1', Money::minor(100, 'EGP'), new PaymentResult(true, PaymentStatus::Pending),
    ));

    expect($activity->records[0]->apiResponses)->toBe([]);
});

it('attaches the calls made since the last event to the record', function (): void {
    $activity = new InMemoryPaymentActivity;
    $recorder = new ApiResponseRecorder;
    $client = new RecordingHttpClient((new FakeHttpClient)->queue(new HttpResponse(200, '{"ok":true}')), $recorder);
    $listener = new RecordingPaymentEventListener($activity, $recorder);

    $client->send(new HttpRequest('POST', 'https://gateway.test/charge'));

    $listener->handle(new PaymentCharged(
        GatewayName::Fawry, 'ORD-1', Money::minor(100, 'EGP'), new PaymentResult(true, PaymentStatus::Pending),
    ));

    expect($activity->records[0]->apiResponses)->toHaveCount(1)
        ->and($activity->records[0]->apiResponses[0]->url)->toBe('https://gateway.test/charge');
});

it('gives each event only its own calls, because draining clears the buffer', function (): void {
    $activity = new InMemoryPaymentActivity;
    $recorder = new ApiResponseRecorder;
    $client = new RecordingHttpClient(new FakeHttpClient, $recorder);
    $listener = new RecordingPaymentEventListener($activity, $recorder);

    $client->send(new HttpRequest('POST', 'https://gateway.test/first'));
    $listener->handle(new PaymentCharged(
        GatewayName::Fawry, 'ORD-1', Money::minor(100, 'EGP'), new PaymentResult(true, PaymentStatus::Pending),
    ));

    $client->send(new HttpRequest('POST', 'https://gateway.test/second'));
    $listener->handle(new PaymentCharged(
        GatewayName::Fawry, 'ORD-2', Money::minor(200, 'EGP'), new PaymentResult(true, PaymentStatus::Pending),
    ));

    // the double prepends, so records[0] is the most recent event
    expect($activity->records[0]->apiResponses)->toHaveCount(1)
        ->and($activity->records[0]->apiResponses[0]->url)->toBe('https://gateway.test/second')
        ->and($activity->records[1]->apiResponses)->toHaveCount(1)
        ->and($activity->records[1]->apiResponses[0]->url)->toBe('https://gateway.test/first');
});

it('records a DCC quote as an informational step, with whether a rate was offered', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new DccRateQuoted(
        GatewayName::CybersourceUnifiedCheckout,
        'ORD-1',
        Money::minor(12030, 'EGP'),
        new DccQuote(offered: true, id: 'dcc_1', exchangeRate: '48.00'),
    ));

    $record = $activity->records[0];

    expect($record->operation)->toBe('DccRateQuoted')
        ->and($record->transactionId)->toBe('dcc_1')
        ->and($record->reference)->toBe('offered')
        ->and($record->status)->toBeNull()
        ->and($record->amountMinor)->toBe(12030);
});

it('marks a quote the card was not eligible for without calling it a failure', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new DccRateQuoted(
        GatewayName::CybersourceUnifiedCheckout, 'ORD-1', Money::minor(100, 'EGP'), new DccQuote(offered: false),
    ));

    expect($activity->records[0]->reference)->toBe('not offered')
        ->and($activity->records[0]->success)->toBeNull();
});

it('records each 3-D Secure leg as pending while it is in flight', function (): void {
    $activity = new InMemoryPaymentActivity;
    $listener = new RecordingPaymentEventListener($activity);
    $money = Money::minor(12030, 'EGP');

    $listener->handle(new PayerAuthenticationSetUp(
        GatewayName::CybersourceUnifiedCheckout, 'ORD-1',
        new PayerAuthSetupResult(true, 'COMPLETED', referenceId: 'ref_1'),
    ));
    $listener->handle(new PayerAuthenticationEnrolled(
        GatewayName::CybersourceUnifiedCheckout, 'ORD-1', $money,
        new PayerAuthResult(true, 'PENDING_AUTHENTICATION', stepUpUrl: 'https://gw/step', authenticationTransactionId: 'auth_1'),
    ));
    $listener->handle(new PayerAuthenticationValidated(
        GatewayName::CybersourceUnifiedCheckout, 'ORD-1', $money,
        new PayerAuthResult(true, 'AUTHENTICATION_SUCCESSFUL', authenticationTransactionId: 'auth_1'),
    ));

    // the double prepends, so records read newest-first
    expect(array_map(static fn (PaymentActivityRecord $r): string => $r->operation, $activity->records))
        ->toBe(['PayerAuthenticationValidated', 'PayerAuthenticationEnrolled', 'PayerAuthenticationSetUp'])
        ->and(array_map(static fn (PaymentActivityRecord $r): ?PaymentStatus => $r->status, $activity->records))
        ->each->toBe(PaymentStatus::Pending)
        ->and($activity->records[0]->transactionId)->toBe('auth_1')
        ->and($activity->records[0]->reference)->toBe('AUTHENTICATION_SUCCESSFUL')
        ->and($activity->records[2]->transactionId)->toBe('ref_1');
});

it('records a failed authentication leg as failed', function (): void {
    $activity = new InMemoryPaymentActivity;

    (new RecordingPaymentEventListener($activity))->handle(new PayerAuthenticationEnrolled(
        GatewayName::CybersourceUnifiedCheckout, 'ORD-1', Money::minor(100, 'EGP'),
        new PayerAuthResult(false, 'AUTHENTICATION_FAILED'),
    ));

    expect($activity->records[0]->status)->toBe(PaymentStatus::Failed)
        ->and($activity->records[0]->success)->toBeFalse();
});
