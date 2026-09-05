<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\DccRateRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthSetupRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\Event\AuthorizationReversed;
use Hyprpay\Payments\Domain\Event\CheckoutSessionCreated;
use Hyprpay\Payments\Domain\Event\DccRateQuoted;
use Hyprpay\Payments\Domain\Event\InstrumentVaulted;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationEnrolled;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationSetUp;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationValidated;
use Hyprpay\Payments\Domain\Event\PaymentCaptured;
use Hyprpay\Payments\Domain\Event\PaymentCharged;
use Hyprpay\Payments\Domain\Event\PaymentRefunded;
use Hyprpay\Payments\Domain\Event\PaymentVoided;
use Hyprpay\Payments\Domain\Event\StoredCredentialCharged;
use Hyprpay\Payments\Domain\Event\WalletCharged;
use Hyprpay\Payments\Domain\Event\WebhookReceived;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\Result\PayerAuthSetupResult;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\EncryptedWalletToken;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Events\RecordingEventDispatcher;
use Hyprpay\Payments\Infrastructure\Gateway\EventDispatchingGateway;

/**
 * A stub driver returning canned results for every operation the decorator emits an event for.
 */
function eventStubGateway(): AbstractPaymentGateway
{
    return new class(testCredentials()) extends AbstractPaymentGateway
    {
        public function name(): GatewayName
        {
            return GatewayName::PayPal;
        }

        public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
        {
            return new CheckoutSession(reference: 'SESS-1', merchantReference: $request->orderReference);
        }

        public function charge(ChargeRequest $request): PaymentResult
        {
            return new PaymentResult(true, PaymentStatus::Captured, 'CH-1');
        }

        public function capture(CaptureRequest $request): PaymentResult
        {
            return new PaymentResult(true, PaymentStatus::Captured, 'CAP-1');
        }

        public function refund(RefundRequest $request): RefundResult
        {
            return new RefundResult(true, PaymentStatus::Refunded, 'REF-1');
        }

        public function void(VoidRequest $request): PaymentResult
        {
            return new PaymentResult(true, PaymentStatus::Voided, 'VOID-1');
        }

        public function reverseAuthorization(ReversalRequest $request): PaymentResult
        {
            return new PaymentResult(true, PaymentStatus::Reversed, 'REV-1');
        }

        public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult
        {
            return new PaymentResult(true, PaymentStatus::Captured, 'SC-1');
        }

        public function chargeWallet(WalletChargeRequest $request): PaymentResult
        {
            return new PaymentResult(true, PaymentStatus::Captured, 'WAL-1');
        }

        public function vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument
        {
            return new VaultedInstrument(true, customerId: 'CU-1', paymentInstrumentId: 'PI-1');
        }

        public function getTransaction(string $transactionId): TransactionSnapshot
        {
            return new TransactionSnapshot($transactionId, PaymentStatus::Captured);
        }

        public function searchTransaction(string $query): TransactionSnapshot
        {
            return new TransactionSnapshot('T-1', PaymentStatus::Captured);
        }

        public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
        {
            return new WebhookEvent(true, 'PAYMENT.CAPTURE.COMPLETED', 'WT-1', PaymentStatus::Captured);
        }

        public function requestDccRate(DccRateRequest $request): DccQuote
        {
            return new DccQuote(offered: true, id: 'DCC-1', exchangeRate: '48.00');
        }

        public function setupPayerAuth(PayerAuthSetupRequest $request): PayerAuthSetupResult
        {
            return new PayerAuthSetupResult(true, 'COMPLETED', referenceId: 'PA-REF-1');
        }

        public function enrollPayerAuth(PayerAuthEnrollRequest $request): PayerAuthResult
        {
            return new PayerAuthResult(true, 'PENDING_AUTHENTICATION', stepUpUrl: 'https://gw/step', authenticationTransactionId: 'PA-1');
        }

        public function validatePayerAuth(ValidatePayerAuthRequest $request): PayerAuthResult
        {
            return new PayerAuthResult(true, 'AUTHENTICATION_SUCCESSFUL', authenticationTransactionId: 'PA-1');
        }
    };
}

/**
 * @return array{0: EventDispatchingGateway, 1: RecordingEventDispatcher}
 */
function eventGateway(): array
{
    $events = new RecordingEventDispatcher;

    return [new EventDispatchingGateway(eventStubGateway(), $events), $events];
}

function usd(): Money
{
    return Money::minor(10000, 'USD');
}

it('dispatches CheckoutSessionCreated after a checkout session is created', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->createCheckoutSession(new CheckoutSessionRequest(money: usd(), orderReference: 'ORD-1'));

    expect($events->count())->toBe(1)
        ->and($events->last())->toBeInstanceOf(CheckoutSessionCreated::class);
    $event = $events->last();
    expect($event->gateway())->toBe(GatewayName::PayPal)
        ->and($event->orderReference)->toBe('ORD-1')
        ->and($event->session->reference)->toBe('SESS-1');
});

it('dispatches PaymentCharged after a charge, carrying the result', function (): void {
    [$gateway, $events] = eventGateway();

    $result = $gateway->charge(new ChargeRequest(transientToken: 'tok', money: usd(), orderReference: 'ORD-1'));

    $event = $events->last();
    expect($event)->toBeInstanceOf(PaymentCharged::class)
        ->and($event->orderReference)->toBe('ORD-1')
        ->and($event->money->toDecimalString())->toBe('100.00')
        ->and($event->result)->toBe($result)
        ->and($event->result->transactionId)->toBe('CH-1');
});

it('dispatches PaymentCaptured with the authorization id', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->capture(new CaptureRequest(transactionId: 'AUTH-1', money: usd(), orderReference: 'ORD-1'));

    $event = $events->last();
    expect($event)->toBeInstanceOf(PaymentCaptured::class)
        ->and($event->transactionId)->toBe('AUTH-1')
        ->and($event->result->transactionId)->toBe('CAP-1');
});

it('dispatches PaymentRefunded with the capture id', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->refund(new RefundRequest(transactionId: 'CAP-1', money: usd(), orderReference: 'ORD-1'));

    $event = $events->last();
    expect($event)->toBeInstanceOf(PaymentRefunded::class)
        ->and($event->transactionId)->toBe('CAP-1')
        ->and($event->result->refundId)->toBe('REF-1');
});

it('dispatches PaymentVoided', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->void(new VoidRequest(transactionId: 'AUTH-1', orderReference: 'ORD-1'));

    expect($events->last())->toBeInstanceOf(PaymentVoided::class)
        ->and($events->last()->transactionId)->toBe('AUTH-1');
});

it('dispatches AuthorizationReversed', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->reverseAuthorization(new ReversalRequest(transactionId: 'AUTH-1', money: usd(), orderReference: 'ORD-1'));

    expect($events->last())->toBeInstanceOf(AuthorizationReversed::class)
        ->and($events->last()->result->status)->toBe(PaymentStatus::Reversed);
});

it('dispatches WalletCharged with the wallet type', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->chargeWallet(new WalletChargeRequest(token: new EncryptedWalletToken('{"data":"x"}'), wallet: WalletType::ApplePay, money: usd(), orderReference: 'ORD-1'));

    expect($events->last())->toBeInstanceOf(WalletCharged::class)
        ->and($events->last()->wallet)->toBe(WalletType::ApplePay)
        ->and($events->last()->result->transactionId)->toBe('WAL-1');
});

it('dispatches StoredCredentialCharged with the instrument id', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->chargeStoredCredential(new StoredCredentialChargeRequest(paymentInstrumentId: 'PI-9', money: usd(), orderReference: 'ORD-1'));

    expect($events->last())->toBeInstanceOf(StoredCredentialCharged::class)
        ->and($events->last()->paymentInstrumentId)->toBe('PI-9');
});

it('dispatches InstrumentVaulted with the customer reference', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->vaultInstrument(new TokenizeInstrumentRequest(cardNumber: '4111111111111111', expirationMonth: '12', expirationYear: '2030', customerReference: 'CU-REF'));

    $event = $events->last();
    expect($event)->toBeInstanceOf(InstrumentVaulted::class)
        ->and($event->customerReference)->toBe('CU-REF')
        ->and($event->result->paymentInstrumentId)->toBe('PI-1');
});

it('dispatches WebhookReceived carrying the verified event', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->verifyWebhook('{}', []);

    $event = $events->last();
    expect($event)->toBeInstanceOf(WebhookReceived::class)
        ->and($event->webhook->verified)->toBeTrue()
        ->and($event->webhook->transactionId)->toBe('WT-1');
});

it('does not dispatch events for read-only operations', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->getTransaction('T-1');
    $gateway->searchTransaction('ORD-1');

    expect($events->count())->toBe(0);
});

it('propagates a thrown exception without dispatching an event', function (): void {
    $inner = new class(testCredentials()) extends AbstractPaymentGateway
    {
        public function name(): GatewayName
        {
            return GatewayName::PayPal;
        }

        public function charge(ChargeRequest $request): PaymentResult
        {
            throw new GatewayRequestException(status: 500, responseBody: '');
        }
    };
    $events = new RecordingEventDispatcher;
    $gateway = new EventDispatchingGateway($inner, $events);

    expect(fn (): PaymentResult => $gateway->charge(new ChargeRequest(transientToken: 'tok', money: usd())))
        ->toThrow(GatewayRequestException::class)
        ->and($events->count())->toBe(0);
});

it('exposes the inner name and credentials', function (): void {
    [$gateway] = eventGateway();

    expect($gateway->name())->toBe(GatewayName::PayPal)
        ->and($gateway->credentials())->toBeInstanceOf(GatewayCredentials::class);
});

it('dispatches DccRateQuoted so a quote that never becomes a charge is still visible', function (): void {
    [$gateway, $events] = eventGateway();

    $quote = $gateway->requestDccRate(new DccRateRequest(money: usd(), cardNumber: '4111111111111111', orderReference: 'ORD-1'));

    $event = $events->last();
    expect($event)->toBeInstanceOf(DccRateQuoted::class)
        ->and($event->gateway())->toBe(GatewayName::PayPal)
        ->and($event->orderReference)->toBe('ORD-1')
        ->and($event->quote)->toBe($quote)
        ->and($event->quote->id)->toBe('DCC-1');
});

it('dispatches an event for each 3-D Secure leg', function (): void {
    [$gateway, $events] = eventGateway();

    $gateway->setupPayerAuth(new PayerAuthSetupRequest(transientToken: 'tok', orderReference: 'ORD-1'));
    $gateway->enrollPayerAuth(new PayerAuthEnrollRequest(transientToken: 'tok', money: usd(), orderReference: 'ORD-1'));
    $gateway->validatePayerAuth(new ValidatePayerAuthRequest(authenticationTransactionId: 'PA-1', money: usd(), orderReference: 'ORD-1'));

    expect($events->count())->toBe(3)
        ->and($events->dispatched()[0])->toBeInstanceOf(PayerAuthenticationSetUp::class)
        ->and($events->dispatched()[1])->toBeInstanceOf(PayerAuthenticationEnrolled::class)
        ->and($events->dispatched()[2])->toBeInstanceOf(PayerAuthenticationValidated::class)
        ->and($events->dispatched()[0]->result->referenceId)->toBe('PA-REF-1')
        ->and($events->dispatched()[1]->result->stepUpUrl)->toBe('https://gw/step')
        ->and($events->dispatched()[2]->result->authenticationTransactionId)->toBe('PA-1');
});
