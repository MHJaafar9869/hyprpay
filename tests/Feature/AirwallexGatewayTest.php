<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Airwallex\AirwallexGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * Airwallex demo credentials: the client id is the merchant id (x-client-id), the API key the
 * shared secret (x-api-key), and the webhook secret the HMAC key for signature verification. The
 * api-version and connected-account id are carried in the credentials' extra bag.
 */
function airwallexCredentials(): GatewayCredentials
{
    return new GatewayCredentials(
        host: 'api-demo.airwallex.com',
        merchantId: 'CLIENT_ID',
        apiKeyId: '',
        sharedSecret: 'API_KEY',
        testMode: true,
        webhookSecret: 'WEBHOOK_SECRET',
        currency: 'USD',
        extra: ['api_version' => '2025-11-11', 'account_id' => 'acct_123'],
    );
}

/**
 * A fake HTTP client pre-seeded with the login response every call authenticates with.
 */
function airwallexHttp(): FakeHttpClient
{
    return (new FakeHttpClient)->queueJson(['token' => 'AWX_TOKEN']);
}

/**
 * Decode the most recent request body sent through the fake client.
 *
 * @return array<string, mixed>
 */
function airwallexBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('creates a payment intent and returns the client secret and reference', function (): void {
    $http = airwallexHttp()->queueJson([
        'id' => 'int_123',
        'client_secret' => 'secret_xyz',
        'status' => 'REQUIRES_PAYMENT_METHOD',
        'merchant_order_id' => 'ORD1',
    ]);

    $session = (new AirwallexGateway(airwallexCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
        returnUrl: 'https://shop.test/return',
        description: 'Gold Plan',
    ));

    $login = $http->recorded()[0];

    expect($session->reference)->toBe('int_123')
        ->and($session->jwt)->toBe('secret_xyz')
        ->and($session->merchantReference)->toBe('ORD1')
        ->and($login->url)->toBe('https://api-demo.airwallex.com/api/v1/authentication/login')
        ->and($login->header('x-api-key'))->toBe('API_KEY')
        ->and($login->header('x-client-id'))->toBe('CLIENT_ID')
        ->and($login->header('x-api-version'))->toBe('2025-11-11')
        ->and($login->header('x-login-as'))->toBe('acct_123')
        ->and($http->lastRequest()?->url)->toBe('https://api-demo.airwallex.com/api/v1/pa/payment_intents/create')
        ->and($http->lastRequest()?->header('Authorization'))->toBe('Bearer AWX_TOKEN')
        ->and($http->lastRequest()?->header('x-client-id'))->toBe('CLIENT_ID')
        ->and(airwallexBody($http)['request_id'])->toBe('ORD1')
        ->and(airwallexBody($http)['merchant_order_id'])->toBe('ORD1')
        ->and(airwallexBody($http)['amount'])->toEqual(100.0)
        ->and(airwallexBody($http)['currency'])->toBe('USD')
        ->and(airwallexBody($http)['return_url'])->toBe('https://shop.test/return')
        ->and(airwallexBody($http)['descriptor'])->toBe('Gold Plan')
        ->and(airwallexBody($http)['payment_method_options']['card']['capture_method'])->toBe('automatic');
});

it('creates a manual-capture intent when the payment method is authorize', function (): void {
    $http = airwallexHttp()->queueJson(['id' => 'int_9', 'status' => 'REQUIRES_PAYMENT_METHOD']);

    (new AirwallexGateway(airwallexCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(5000, 'USD'),
        orderReference: 'ORD9',
        paymentMethod: 'authorize',
    ));

    expect(airwallexBody($http)['payment_method_options']['card']['capture_method'])->toBe('manual');
});

it('captures an authorized payment intent', function (): void {
    $http = airwallexHttp()->queueJson([
        'id' => 'int_123',
        'status' => 'SUCCEEDED',
        'amount' => 60,
        'currency' => 'USD',
    ]);

    $result = (new AirwallexGateway(airwallexCredentials(), $http))->capture(new CaptureRequest(
        transactionId: 'int_123',
        money: Money::minor(6000, 'USD'),
        orderReference: 'ORD1',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('int_123')
        ->and($http->lastRequest()?->url)->toBe('https://api-demo.airwallex.com/api/v1/pa/payment_intents/int_123/capture')
        ->and($http->lastRequest()?->method)->toBe('POST')
        ->and(airwallexBody($http)['request_id'])->toBe('ORD1')
        ->and(airwallexBody($http)['amount'])->toEqual(60.0);
});

it('refunds a captured payment intent', function (): void {
    $http = airwallexHttp()->queueJson([
        'id' => 'rfd_1',
        'status' => 'SUCCEEDED',
        'amount' => 10,
    ]);

    $result = (new AirwallexGateway(airwallexCredentials(), $http))->refund(new RefundRequest(
        transactionId: 'int_123',
        money: Money::minor(1000, 'USD'),
        reason: 'Defective product',
        orderReference: 'ORD1',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->refundId)->toBe('rfd_1')
        ->and($http->lastRequest()?->url)->toBe('https://api-demo.airwallex.com/api/v1/pa/refunds/create')
        ->and(airwallexBody($http)['payment_intent_id'])->toBe('int_123')
        ->and(airwallexBody($http)['amount'])->toEqual(10.0)
        ->and(airwallexBody($http)['reason'])->toBe('Defective product')
        ->and(airwallexBody($http)['request_id'])->toBe('ORD1');
});

it('reports a still-processing refund as pending', function (): void {
    $http = airwallexHttp()->queueJson(['id' => 'rfd_2', 'status' => 'RECEIVED']);

    $result = (new AirwallexGateway(airwallexCredentials(), $http))->refund(new RefundRequest(
        transactionId: 'int_123',
        money: Money::minor(1000, 'USD'),
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Pending)
        ->and(airwallexBody($http)['request_id'])->toBe('int_123');
});

it('charges a stored credential by creating then confirming an intent against a consent', function (): void {
    $http = airwallexHttp()
        ->queueJson(['id' => 'int_sc', 'status' => 'REQUIRES_PAYMENT_METHOD'])
        ->queueJson(['id' => 'int_sc', 'status' => 'SUCCEEDED']);

    $result = (new AirwallexGateway(airwallexCredentials(), $http))->chargeStoredCredential(new StoredCredentialChargeRequest(
        paymentInstrumentId: 'cst_consent1',
        money: Money::minor(50000, 'USD'),
        initiator: CredentialInitiator::Merchant,
        customerId: 'cus_1',
        orderReference: 'ORD-SUB',
    ));

    $requests = $http->recorded();
    $createBody = json_decode((string) $requests[1]->body, true);
    $confirmBody = json_decode((string) $requests[2]->body, true);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('int_sc')
        ->and($requests[1]->url)->toBe('https://api-demo.airwallex.com/api/v1/pa/payment_intents/create')
        ->and($requests[2]->url)->toBe('https://api-demo.airwallex.com/api/v1/pa/payment_intents/int_sc/confirm')
        ->and($createBody['amount'])->toEqual(500.0)
        ->and($createBody['currency'])->toBe('USD')
        ->and($createBody['customer_id'])->toBe('cus_1')
        ->and($createBody['request_id'])->toBe('ORD-SUB')
        ->and($confirmBody['payment_consent_id'])->toBe('cst_consent1')
        ->and($confirmBody['request_id'])->toBe('ORD-SUB-confirm');
});

it('looks up a payment intent and maps its status, amount, and reference', function (): void {
    $http = airwallexHttp()->queueJson([
        'id' => 'int_123',
        'status' => 'SUCCEEDED',
        'amount' => 123.45,
        'currency' => 'USD',
        'merchant_order_id' => 'ORD1',
    ]);

    $snapshot = (new AirwallexGateway(airwallexCredentials(), $http))->getTransaction('int_123');

    expect($snapshot->status)->toBe(PaymentStatus::Captured)
        ->and($snapshot->transactionId)->toBe('int_123')
        ->and($snapshot->orderReference)->toBe('ORD1')
        ->and($snapshot->money?->toDecimalString())->toBe('123.45')
        ->and($snapshot->money?->currency)->toBe('USD')
        ->and($http->lastRequest()?->url)->toBe('https://api-demo.airwallex.com/api/v1/pa/payment_intents/int_123')
        ->and($http->lastRequest()?->method)->toBe('GET');
});

it('maps an authorization-held intent to authorized', function (): void {
    $http = airwallexHttp()->queueJson(['id' => 'int_123', 'status' => 'REQUIRES_CAPTURE']);

    $snapshot = (new AirwallexGateway(airwallexCredentials(), $http))->getTransaction('int_123');

    expect($snapshot->status)->toBe(PaymentStatus::Authorized);
});

it('verifies a genuine webhook signature', function (): void {
    $rawBody = (string) json_encode([
        'name' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'int_123', 'status' => 'SUCCEEDED', 'amount' => 100, 'currency' => 'USD']],
    ]);
    $timestamp = '1699999999';
    $signature = hash_hmac('sha256', $timestamp.$rawBody, 'WEBHOOK_SECRET');

    $result = (new AirwallexGateway(airwallexCredentials(), new FakeHttpClient))->verifyWebhook($rawBody, [
        'x-timestamp' => $timestamp,
        'x-signature' => $signature,
    ]);

    expect($result->verified)->toBeTrue()
        ->and($result->eventType)->toBe('payment_intent.succeeded')
        ->and($result->transactionId)->toBe('int_123')
        ->and($result->status)->toBe(PaymentStatus::Captured);
});

it('rejects a webhook with a tampered signature', function (): void {
    $rawBody = (string) json_encode([
        'name' => 'payment_intent.cancelled',
        'data' => ['object' => ['id' => 'int_123', 'status' => 'CANCELLED']],
    ]);

    $result = (new AirwallexGateway(airwallexCredentials(), new FakeHttpClient))->verifyWebhook($rawBody, [
        'x-timestamp' => '1699999999',
        'x-signature' => 'not-the-real-signature',
    ]);

    expect($result->verified)->toBeFalse()
        ->and($result->eventType)->toBe('payment_intent.cancelled')
        ->and($result->status)->toBe(PaymentStatus::Voided);
});

it('authenticates only once across multiple operations on the same instance', function (): void {
    $http = airwallexHttp()
        ->queueJson(['id' => 'int_123', 'client_secret' => 'secret', 'status' => 'REQUIRES_PAYMENT_METHOD'])
        ->queueJson(['id' => 'rfd_1', 'status' => 'SUCCEEDED']);

    $gateway = new AirwallexGateway(airwallexCredentials(), $http);
    $gateway->createCheckoutSession(new CheckoutSessionRequest(money: Money::minor(10000, 'USD'), orderReference: 'ORD1'));
    $gateway->refund(new RefundRequest(transactionId: 'int_123', money: Money::minor(1000, 'USD'), orderReference: 'ORD1'));

    $loginCalls = array_filter(
        $http->recorded(),
        static fn (HttpRequest $request): bool => str_ends_with($request->url, '/api/v1/authentication/login'),
    );

    expect($loginCalls)->toHaveCount(1)
        ->and($http->requestCount())->toBe(3);
});

it('is resolvable through the factory', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(airwallexCredentials()));

    expect($factory->make(GatewayName::Airwallex, airwallexCredentials()))->toBeInstanceOf(AirwallexGateway::class);
});
