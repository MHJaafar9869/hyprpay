<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobHmac;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @param  array<string, mixed>  $extra
 */
function paymobCredentials(array $extra = []): GatewayCredentials
{
    return new GatewayCredentials(
        host: 'accept.paymob.com',
        merchantId: '',
        apiKeyId: '',
        sharedSecret: 'API_KEY',
        testMode: true,
        webhookSecret: 'HMAC_SECRET',
        extra: $extra ?: [
            'integrations' => ['card' => 111, 'valu' => 222],
            'iframes' => ['card' => 999, 'valu' => 888],
        ],
    );
}

/**
 * @return array<string, mixed>
 */
function paymobRecorded(FakeHttpClient $http, int $index): array
{
    return json_decode((string) $http->recorded()[$index]->body, true) ?? [];
}

it('runs the auth, order and payment-key flow and returns the iframe URL', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson(['id' => 12345])
        ->queueJson(['token' => 'PAY_TOKEN']);
    $gateway = new PaymobGateway(paymobCredentials(), $http);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        orderReference: 'ORD1',
        customer: new Customer(email: 'a@b.com', firstName: 'A', lastName: 'B'),
        paymentMethod: 'card',
        options: new PaymobCheckoutOptions(customerMobile: '01000000000'),
    ));

    expect($session->reference)->toBe('12345')
        ->and($session->redirectUrl)->toBe('https://accept.paymob.com/api/acceptance/iframes/999?payment_token=PAY_TOKEN')
        ->and($http->requestCount())->toBe(3)
        ->and(paymobRecorded($http, 0)['api_key'])->toBe('API_KEY')
        ->and($http->recorded()[1]->header('Authorization'))->toBe('Bearer AUTH_TOKEN')
        ->and(paymobRecorded($http, 1)['amount_cents'])->toBe(10000)
        ->and(paymobRecorded($http, 1)['merchant_order_id'])->toBe('ORD1')
        ->and(paymobRecorded($http, 2)['integration_id'])->toBe('111')
        ->and(paymobRecorded($http, 2)['billing_data']['phone_number'])->toBe('01000000000')
        ->and(paymobRecorded($http, 2)['billing_data']['floor'])->toBe('NA');
});

it('overrides the integration id, iframe id, and expiry from typed options', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson(['id' => 12345])
        ->queueJson(['token' => 'PAY_TOKEN']);
    $gateway = new PaymobGateway(paymobCredentials(), $http);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        orderReference: 'ORD1',
        paymentMethod: 'card',
        options: new PaymobCheckoutOptions(integrationId: 555, iframeId: 777, expiration: 600),
    ));

    expect(paymobRecorded($http, 2)['integration_id'])->toBe('555')
        ->and(paymobRecorded($http, 2)['expiration'])->toBe(600)
        ->and($session->redirectUrl)->toBe('https://accept.paymob.com/api/acceptance/iframes/777?payment_token=PAY_TOKEN');
});

it('refunds a transaction by id and amount', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson(['id' => 777, 'error_occured' => false]);
    $gateway = new PaymobGateway(paymobCredentials(), $http);

    $result = $gateway->refund(new RefundRequest(transactionId: '555', money: Money::minor(5000, 'EGP')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->refundId)->toBe('777')
        ->and($http->lastRequest()?->url)->toBe('https://accept.paymob.com/api/acceptance/void_refund/refund')
        ->and(paymobRecorded($http, 1)['transaction_id'])->toBe('555')
        ->and(paymobRecorded($http, 1)['amount_cents'])->toBe(5000);
});

it('voids a transaction by id', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson(['id' => 777, 'error_occured' => false]);
    $gateway = new PaymobGateway(paymobCredentials(), $http);

    $result = $gateway->void(new VoidRequest(transactionId: '555'));

    expect($result->status)->toBe(PaymentStatus::Voided)
        ->and($http->lastRequest()?->url)->toBe('https://accept.paymob.com/api/acceptance/void_refund/void');
});

it('captures an authorized transaction by id and amount', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson(['id' => 888, 'success' => true, 'pending' => false, 'error_occured' => false]);
    $gateway = new PaymobGateway(paymobCredentials(), $http);

    $result = $gateway->capture(new CaptureRequest(transactionId: '555', money: Money::minor(7500, 'EGP')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('888')
        ->and($http->lastRequest()?->url)->toBe('https://accept.paymob.com/api/acceptance/capture')
        ->and($http->recorded()[1]->header('Authorization'))->toBe('Bearer AUTH_TOKEN')
        ->and(paymobRecorded($http, 1)['transaction_id'])->toBe('555')
        ->and(paymobRecorded($http, 1)['amount_cents'])->toBe(7500);
});

it('reports a failed capture when Paymob flags an error', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson(['id' => 888, 'error_occured' => true, 'data' => ['message' => 'declined']]);
    $gateway = new PaymobGateway(paymobCredentials(), $http);

    $result = $gateway->capture(new CaptureRequest(transactionId: '555', money: Money::minor(7500, 'EGP')));

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->message)->toBe('declined');
});

it('searches for a transaction by merchant order id', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson(['id' => 999, 'success' => true, 'pending' => false, 'order' => ['merchant_order_id' => 'ORD1']]);
    $gateway = new PaymobGateway(paymobCredentials(), $http);

    $snapshot = $gateway->searchTransaction('ORD1');

    expect($snapshot?->status)->toBe(PaymentStatus::Captured)
        ->and($snapshot?->transactionId)->toBe('999')
        ->and($snapshot?->orderReference)->toBe('ORD1')
        ->and($http->lastRequest()?->url)->toBe('https://accept.paymob.com/api/ecommerce/orders/transaction_inquiry')
        ->and(paymobRecorded($http, 1)['merchant_order_id'])->toBe('ORD1');
});

it('returns null when searching for an unknown merchant order id', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson([]);
    $gateway = new PaymobGateway(paymobCredentials(), $http);

    expect($gateway->searchTransaction('NOPE'))->toBeNull();
});

it('maps an order inquiry to a captured snapshot', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['token' => 'AUTH_TOKEN'])
        ->queueJson(['id' => 999, 'success' => true, 'pending' => false, 'order' => ['merchant_order_id' => 'ORD1']]);
    $gateway = new PaymobGateway(paymobCredentials(), $http);

    $snapshot = $gateway->getTransaction('12345');

    expect($snapshot->status)->toBe(PaymentStatus::Captured)
        ->and($snapshot->transactionId)->toBe('999')
        ->and($snapshot->orderReference)->toBe('ORD1');
});

it('verifies a correctly signed Paymob webhook', function (): void {
    $gateway = new PaymobGateway(paymobCredentials(), new FakeHttpClient);
    $object = paymobTransactionObject();
    $body = (string) json_encode(['type' => 'TRANSACTION', 'obj' => $object]);

    $event = $gateway->verifyWebhook($body, ['hmac' => PaymobHmac::forTransaction($object, 'HMAC_SECRET')]);

    expect($event->verified)->toBeTrue()
        ->and($event->status)->toBe(PaymentStatus::Captured)
        ->and($event->transactionId)->toBe('123');
});

it('rejects a Paymob webhook with a bad HMAC', function (): void {
    $gateway = new PaymobGateway(paymobCredentials(), new FakeHttpClient);
    $body = (string) json_encode(['type' => 'TRANSACTION', 'obj' => paymobTransactionObject()]);

    expect($gateway->verifyWebhook($body, ['hmac' => 'deadbeef'])->verified)->toBeFalse();
});

it('throws when no integration id is configured for the method', function (): void {
    $gateway = new PaymobGateway(paymobCredentials(['integrations' => [], 'iframes' => []]), new FakeHttpClient);

    expect(fn (): CheckoutSession => $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(100, 'EGP'),
        orderReference: 'X',
        paymentMethod: 'card',
    )))->toThrow(GatewayRequestException::class);
});

it('is resolvable through the factory', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(paymobCredentials()));

    expect($factory->make(GatewayName::Paymob, paymobCredentials()))->toBeInstanceOf(PaymobGateway::class);
});
