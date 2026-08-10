<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\DccRateRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Exception\UnsupportedOperationException;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\MpgsGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @param  array<string, string>  $overrides
 */
function mpgsCredentials(array $overrides = []): GatewayCredentials
{
    return new GatewayCredentials(
        host: $overrides['host'] ?? 'test-gateway.mastercard.com',
        merchantId: $overrides['merchantId'] ?? 'TESTMERCHANT',
        apiKeyId: '',
        sharedSecret: $overrides['sharedSecret'] ?? 'api-password',
        testMode: true,
        webhookSecret: $overrides['webhookSecret'] ?? 'notif-secret',
    );
}

/**
 * @param  array<string, string>  $overrides
 * @return array{0: MpgsGateway, 1: FakeHttpClient}
 */
function mpgsWithFakeHttp(array $overrides = []): array
{
    $http = new FakeHttpClient;

    return [new MpgsGateway(mpgsCredentials($overrides), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function mpgsBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('initiates a hosted checkout session', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'SUCCESS', 'session' => ['id' => 'SESSION0002']]);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(25000, 'USD'),
        orderReference: 'ORD1',
        description: 'Goods and Services',
    ));

    $request = $http->lastRequest();

    expect($session->reference)->toBe('SESSION0002')
        ->and($session->merchantReference)->toBe('ORD1')
        ->and($session->clientLibrary)->toBe('https://test-gateway.mastercard.com/static/checkout/checkout.min.js')
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://test-gateway.mastercard.com/api/rest/version/100/merchant/TESTMERCHANT/session')
        ->and(mpgsBody($http)['apiOperation'])->toBe('INITIATE_CHECKOUT')
        ->and(data_get(mpgsBody($http), 'order.id'))->toBe('ORD1')
        ->and(data_get(mpgsBody($http), 'order.amount'))->toBe('250.00');
});

it('charges a session with a PAY transaction and maps a captured response', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson([
        'result' => 'SUCCESS',
        'response' => ['gatewayCode' => 'APPROVED'],
        'transaction' => ['id' => 'TXN1'],
        'order' => ['status' => 'CAPTURED'],
    ]);

    $result = $gateway->charge(new ChargeRequest(
        transientToken: 'sess-1',
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
        idempotencyKey: 'TXN1',
    ));

    $request = $http->lastRequest();

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('TXN1')
        ->and($result->code)->toBe('APPROVED')
        ->and($request?->method)->toBe('PUT')
        ->and($request?->url)->toBe('https://test-gateway.mastercard.com/api/rest/version/100/merchant/TESTMERCHANT/order/ORD1/transaction/TXN1')
        ->and($request?->header('Authorization'))->toBe('Basic '.base64_encode('merchant.TESTMERCHANT:api-password'))
        ->and(mpgsBody($http)['apiOperation'])->toBe('PAY')
        ->and(data_get(mpgsBody($http), 'session.id'))->toBe('sess-1')
        ->and(data_get(mpgsBody($http), 'order.amount'))->toBe('100.00');
});

it('authorises a session with an AUTHORIZE transaction when capture is disabled', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'SUCCESS', 'response' => ['gatewayCode' => 'APPROVED'], 'transaction' => ['id' => 'TXN1']]);

    $result = $gateway->charge(new ChargeRequest(
        transientToken: 'sess-1',
        money: Money::minor(10000, 'USD'),
        capture: false,
        orderReference: 'ORD1',
        idempotencyKey: 'TXN1',
    ));

    expect($result->status)->toBe(PaymentStatus::Authorized)
        ->and(mpgsBody($http)['apiOperation'])->toBe('AUTHORIZE');
});

it('maps a declined transaction to a failed payment result', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'FAILURE', 'response' => ['gatewayCode' => 'DECLINED']]);

    $result = $gateway->charge(new ChargeRequest(
        transientToken: 'sess-1',
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
        idempotencyKey: 'TXN1',
    ));

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(PaymentStatus::Declined)
        ->and($result->code)->toBe('DECLINED');
});

it('captures an authorization against the order', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'SUCCESS', 'transaction' => ['id' => 'CAP1'], 'order' => ['status' => 'CAPTURED']]);

    $result = $gateway->capture(new CaptureRequest(
        transactionId: '1',
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
        idempotencyKey: 'CAP1',
    ));

    expect($result->status)->toBe(PaymentStatus::Captured)
        ->and($http->lastRequest()?->url)->toBe('https://test-gateway.mastercard.com/api/rest/version/100/merchant/TESTMERCHANT/order/ORD1/transaction/CAP1')
        ->and(mpgsBody($http)['apiOperation'])->toBe('CAPTURE')
        ->and(data_get(mpgsBody($http), 'transaction.amount'))->toBe('100.00');
});

it('requires an order reference to capture', function (): void {
    [$gateway] = mpgsWithFakeHttp();

    expect(fn (): PaymentResult => $gateway->capture(new CaptureRequest(transactionId: '1', money: Money::minor(10000, 'USD'))))
        ->toThrow(GatewayRequestException::class);
});

it('refunds a captured payment', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'SUCCESS', 'transaction' => ['id' => 'REF1']]);

    $result = $gateway->refund(new RefundRequest(
        transactionId: '1',
        money: Money::minor(4000, 'USD'),
        orderReference: 'ORD1',
        idempotencyKey: 'REF1',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->refundId)->toBe('REF1')
        ->and(mpgsBody($http)['apiOperation'])->toBe('REFUND')
        ->and(data_get(mpgsBody($http), 'transaction.amount'))->toBe('40.00');
});

it('voids a transaction by targeting the prior transaction id', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'SUCCESS', 'transaction' => ['id' => 'VOID1']]);

    $result = $gateway->void(new VoidRequest(transactionId: '1', orderReference: 'ORD1', idempotencyKey: 'VOID1'));

    expect($result->status)->toBe(PaymentStatus::Voided)
        ->and(mpgsBody($http)['apiOperation'])->toBe('VOID')
        ->and(data_get(mpgsBody($http), 'transaction.targetTransactionId'))->toBe('1');
});

it('reverses an authorization via a void of the authorization', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'SUCCESS', 'transaction' => ['id' => 'REV1']]);

    $result = $gateway->reverseAuthorization(new ReversalRequest(
        transactionId: '1',
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
        idempotencyKey: 'REV1',
    ));

    expect($result->status)->toBe(PaymentStatus::Reversed)
        ->and(mpgsBody($http)['apiOperation'])->toBe('VOID')
        ->and(data_get(mpgsBody($http), 'transaction.targetTransactionId'))->toBe('1');
});

it('tokenises a card', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'SUCCESS', 'token' => 'tok-xyz', 'status' => 'VALID']);

    $result = $gateway->vaultInstrument(new TokenizeInstrumentRequest(
        cardNumber: '5123450000000008',
        expirationMonth: '05',
        expirationYear: '2027',
    ));

    $request = $http->lastRequest();

    expect($result->success)->toBeTrue()
        ->and($result->paymentInstrumentId)->toBe('tok-xyz')
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://test-gateway.mastercard.com/api/rest/version/100/merchant/TESTMERCHANT/token')
        ->and(data_get(mpgsBody($http), 'sourceOfFunds.provided.card.number'))->toBe('5123450000000008')
        ->and(data_get(mpgsBody($http), 'sourceOfFunds.provided.card.expiry.year'))->toBe('27')
        ->and(data_get(mpgsBody($http), 'sourceOfFunds.provided.card.expiry.month'))->toBe('05');
});

it('charges a stored credential token', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'SUCCESS', 'transaction' => ['id' => 'SC1'], 'order' => ['status' => 'CAPTURED']]);

    $result = $gateway->chargeStoredCredential(new StoredCredentialChargeRequest(
        paymentInstrumentId: 'tok-xyz',
        money: Money::minor(10000, 'USD'),
        initiator: CredentialInitiator::Merchant,
        orderReference: 'ORD1',
        idempotencyKey: 'SC1',
    ));

    expect($result->status)->toBe(PaymentStatus::Captured)
        ->and(mpgsBody($http)['apiOperation'])->toBe('PAY')
        ->and(data_get(mpgsBody($http), 'sourceOfFunds.token'))->toBe('tok-xyz')
        ->and(data_get(mpgsBody($http), 'sourceOfFunds.provided.card.storedOnFile'))->toBe('STORED')
        ->and(data_get(mpgsBody($http), 'transaction.source'))->toBe('INTERNET')
        ->and(data_get(mpgsBody($http), 'agreement.type'))->toBe('OTHER');
});

it('initiates 3-D Secure payer authentication and surfaces the challenge url', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson([
        'result' => 'SUCCESS',
        'response' => ['gatewayRecommendation' => 'PROCEED'],
        'authentication' => ['version' => '3DS2', '3ds2' => ['acsUrl' => 'https://acs.test/challenge']],
        'transaction' => ['id' => 'AUTH1'],
    ]);

    $result = $gateway->enrollPayerAuth(new PayerAuthEnrollRequest(
        transientToken: 'sess-1',
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
    ));

    $request = $http->lastRequest();

    expect($result->success)->toBeTrue()
        ->and($result->requiresChallenge())->toBeTrue()
        ->and($result->stepUpUrl)->toBe('https://acs.test/challenge')
        ->and($result->authenticationTransactionId)->toBe('AUTH1')
        ->and($request?->method)->toBe('PUT')
        ->and($request?->url)->toContain('/order/ORD1/transaction/')
        ->and(mpgsBody($http)['apiOperation'])->toBe('INITIATE_AUTHENTICATION')
        ->and(data_get(mpgsBody($http), 'authentication.channel'))->toBe('PAYER_BROWSER')
        ->and(data_get(mpgsBody($http), 'session.id'))->toBe('sess-1');
});

it('authenticates the payer on the enrolled transaction', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['result' => 'SUCCESS', 'authentication' => ['version' => '3DS2'], 'transaction' => ['id' => 'AUTH1']]);

    $result = $gateway->validatePayerAuth(new ValidatePayerAuthRequest(
        authenticationTransactionId: 'AUTH1',
        money: Money::minor(10000, 'USD'),
        transientToken: 'sess-1',
        orderReference: 'ORD1',
    ));

    expect($result->success)->toBeTrue()
        ->and($http->lastRequest()?->url)->toBe('https://test-gateway.mastercard.com/api/rest/version/100/merchant/TESTMERCHANT/order/ORD1/transaction/AUTH1')
        ->and(mpgsBody($http)['apiOperation'])->toBe('AUTHENTICATE_PAYER')
        ->and(data_get(mpgsBody($http), 'session.id'))->toBe('sess-1');
});

it('retrieves an order as a transaction snapshot', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['id' => 'ORD1', 'status' => 'CAPTURED', 'amount' => '100.00', 'currency' => 'USD']);

    $snapshot = $gateway->getTransaction('ORD1');

    expect($snapshot->transactionId)->toBe('ORD1')
        ->and($snapshot->status)->toBe(PaymentStatus::Captured)
        ->and($snapshot->orderReference)->toBe('ORD1')
        ->and($snapshot->money?->currency)->toBe('USD')
        ->and($snapshot->money?->minorAmount)->toBe(10000)
        ->and($http->lastRequest()?->method)->toBe('GET')
        ->and($http->lastRequest()?->url)->toBe('https://test-gateway.mastercard.com/api/rest/version/100/merchant/TESTMERCHANT/order/ORD1');
});

it('returns a snapshot when searching for an existing order', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queueJson(['id' => 'ORD1', 'status' => 'AUTHORIZED']);

    $snapshot = $gateway->searchTransaction('ORD1');

    expect($snapshot?->status)->toBe(PaymentStatus::Authorized);
});

it('returns null when searching for an unknown order', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queue(new HttpResponse(404, '{"error":{"cause":"NOT_FOUND"}}'));

    expect($gateway->searchTransaction('missing'))->toBeNull();
});

it('verifies a webhook carrying the notification secret', function (): void {
    [$gateway] = mpgsWithFakeHttp();
    $body = '{"order":{"id":"ORD1","status":"CAPTURED"},"transaction":{"id":"1","type":"PAYMENT"}}';

    $event = $gateway->verifyWebhook($body, ['X-Notification-Secret' => 'notif-secret']);

    expect($event->verified)->toBeTrue()
        ->and($event->status)->toBe(PaymentStatus::Captured)
        ->and($event->transactionId)->toBe('1')
        ->and($event->eventType)->toBe('PAYMENT');
});

it('rejects a webhook with the wrong notification secret', function (): void {
    [$gateway] = mpgsWithFakeHttp();

    $event = $gateway->verifyWebhook('{"order":{"status":"CAPTURED"}}', ['X-Notification-Secret' => 'wrong']);

    expect($event->verified)->toBeFalse();
});

it('rejects a request/validation error with a gateway exception', function (): void {
    [$gateway, $http] = mpgsWithFakeHttp();
    $http->queue(new HttpResponse(400, '{"error":{"explanation":"Invalid currency"}}'));

    expect(fn (): PaymentResult => $gateway->charge(new ChargeRequest(
        transientToken: 'sess-1',
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
        idempotencyKey: 'TXN1',
    )))->toThrow(GatewayRequestException::class);
});

it('does not support DCC rate quotes', function (): void {
    [$gateway] = mpgsWithFakeHttp();

    expect(fn (): DccQuote => $gateway->requestDccRate(new DccRateRequest(money: Money::minor(10000, 'USD'), cardNumber: '5123450000000008')))
        ->toThrow(UnsupportedOperationException::class);
});
