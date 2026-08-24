<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Tamara\TamaraGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: TamaraGateway, 1: FakeHttpClient}
 */
function tamaraWithFakeHttp(): array
{
    $http = new FakeHttpClient;

    return [new TamaraGateway(testCredentials(['host' => 'api-sandbox.tamara.co']), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function tamaraBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('creates a checkout session and returns the redirect URL', function (): void {
    [$gateway, $http] = tamaraWithFakeHttp();
    $http->queueJson(['order_id' => 'ord_1', 'checkout_id' => 'chk_1', 'checkout_url' => 'https://checkout.tamara.co/c/chk_1', 'status' => 'new']);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(30000, 'SAR'),
        orderReference: 'ORDER-1',
        returnUrl: 'https://shop.test/return',
    ));

    expect($session->redirectUrl)->toBe('https://checkout.tamara.co/c/chk_1')
        ->and($session->reference)->toBe('ord_1')
        ->and($session->merchantReference)->toBe('ORDER-1')
        ->and($http->lastRequest()?->url)->toBe('https://api-sandbox.tamara.co/checkout')
        ->and($http->lastRequest()?->header('Authorization'))->toStartWith('Bearer ');

    $body = tamaraBody($http);
    expect($body['total_amount'])->toEqual(['amount' => 300, 'currency' => 'SAR'])
        ->and($body['order_reference_id'])->toBe('ORDER-1')
        ->and($body['payment_type'])->toBe('PAY_BY_INSTALMENTS')
        ->and($body['merchant_url']['success'])->toBe('https://shop.test/return');
});

it('captures an authorised order', function (): void {
    [$gateway, $http] = tamaraWithFakeHttp();
    $http->queueJson(['capture_id' => 'cap_1', 'order_id' => 'ord_1', 'status' => 'fully_captured', 'captured_amount' => ['amount' => 300, 'currency' => 'SAR']]);

    $result = $gateway->capture(new CaptureRequest(transactionId: 'ord_1', money: Money::minor(30000, 'SAR')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('cap_1')
        ->and($http->lastRequest()?->url)->toBe('https://api-sandbox.tamara.co/payments/capture')
        ->and(tamaraBody($http)['order_id'])->toBe('ord_1');
});

it('refunds a captured order with a comment', function (): void {
    [$gateway, $http] = tamaraWithFakeHttp();
    $http->queueJson(['refund_id' => 'ref_1', 'order_id' => 'ord_1', 'capture_id' => 'cap_1', 'status' => 'fully_refunded', 'refunded_amount' => ['amount' => 300, 'currency' => 'SAR']]);

    $result = $gateway->refund(new RefundRequest(transactionId: 'ord_1', money: Money::minor(30000, 'SAR'), reason: 'Customer request'));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->refundId)->toBe('ref_1')
        ->and($http->lastRequest()?->url)->toBe('https://api-sandbox.tamara.co/payments/simplified-refund/ord_1')
        ->and(tamaraBody($http)['comment'])->toBe('Customer request');
});

it('cancels an order, fetching its total first', function (): void {
    [$gateway, $http] = tamaraWithFakeHttp();
    $http->queueJson(['order_id' => 'ord_1', 'status' => 'authorised', 'total_amount' => ['amount' => 300, 'currency' => 'SAR']]);
    $http->queueJson(['order_id' => 'ord_1', 'cancel_id' => 'can_1', 'status' => 'canceled', 'canceled_amount' => ['amount' => 300, 'currency' => 'SAR']]);

    $result = $gateway->void(new VoidRequest(transactionId: 'ord_1'));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Voided)
        ->and($result->transactionId)->toBe('can_1')
        ->and($http->requestCount())->toBe(2)
        ->and($http->lastRequest()?->url)->toBe('https://api-sandbox.tamara.co/orders/ord_1/cancel')
        ->and(tamaraBody($http)['total_amount'])->toEqual(['amount' => 300, 'currency' => 'SAR']);
});

it('reverses an authorization via cancel without an extra lookup', function (): void {
    [$gateway, $http] = tamaraWithFakeHttp();
    $http->queueJson(['order_id' => 'ord_1', 'cancel_id' => 'can_1']);

    $result = $gateway->reverseAuthorization(new ReversalRequest(transactionId: 'ord_1', money: Money::minor(30000, 'SAR')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Reversed)
        ->and($http->requestCount())->toBe(1)
        ->and($http->lastRequest()?->url)->toBe('https://api-sandbox.tamara.co/orders/ord_1/cancel')
        ->and(tamaraBody($http)['total_amount'])->toEqual(['amount' => 300, 'currency' => 'SAR']);
});

it('authorises an approved order', function (): void {
    [$gateway, $http] = tamaraWithFakeHttp();
    $http->queueJson(['order_id' => 'ord_1', 'status' => 'authorised', 'authorized_amount' => ['amount' => 300, 'currency' => 'SAR'], 'capture_id' => '']);

    $result = $gateway->authorise('ord_1');

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Authorized)
        ->and($result->transactionId)->toBe('ord_1')
        ->and($http->lastRequest()?->url)->toBe('https://api-sandbox.tamara.co/orders/ord_1/authorise');
});

it('maps an order to a transaction snapshot', function (): void {
    [$gateway, $http] = tamaraWithFakeHttp();
    $http->queueJson(['order_id' => 'ord_1', 'order_reference_id' => 'ORDER-1', 'status' => 'fully_captured', 'total_amount' => ['amount' => 300, 'currency' => 'SAR']]);

    $snapshot = $gateway->getTransaction('ord_1');

    expect($snapshot->transactionId)->toBe('ord_1')
        ->and($snapshot->status)->toBe(PaymentStatus::Captured)
        ->and($snapshot->orderReference)->toBe('ORDER-1')
        ->and($snapshot->money?->currency)->toBe('SAR')
        ->and($snapshot->money?->toDecimalString())->toBe('300')
        ->and($http->lastRequest()?->url)->toBe('https://api-sandbox.tamara.co/orders/ord_1');
});

it('finds a successful transaction by merchant reference', function (): void {
    [$gateway, $http] = tamaraWithFakeHttp();
    $http->queueJson(['order_id' => 'ord_1', 'order_reference_id' => 'ORDER-1', 'status' => 'fully_captured', 'total_amount' => ['amount' => 300, 'currency' => 'SAR']]);

    $snapshot = $gateway->findSuccessfulTransactionByReference('ORDER-1');

    expect($snapshot)->not->toBeNull()
        ->and($snapshot?->transactionId)->toBe('ord_1')
        ->and($http->lastRequest()?->url)->toBe('https://api-sandbox.tamara.co/merchants/orders/reference-id/ORDER-1');
});

it('returns null when a reference lookup is not found', function (): void {
    [$gateway, $http] = tamaraWithFakeHttp();
    $http->queueJson(['message' => 'not found'], 404);

    expect($gateway->searchTransaction('MISSING'))->toBeNull();
});

it('verifies a webhook signed with the registered authorization header', function (): void {
    [$gateway] = tamaraWithFakeHttp();

    $event = $gateway->verifyWebhook(
        (string) json_encode(['event_type' => 'order_captured', 'order_id' => 'ord_1']),
        ['Authorization' => (string) testCredentials()->webhookSecret],
    );

    expect($event->verified)->toBeTrue()
        ->and($event->eventType)->toBe('order_captured')
        ->and($event->transactionId)->toBe('ord_1')
        ->and($event->status)->toBe(PaymentStatus::Captured);
});

it('rejects a webhook with a wrong authorization header', function (): void {
    [$gateway] = tamaraWithFakeHttp();

    $event = $gateway->verifyWebhook(
        (string) json_encode(['event_type' => 'order_approved', 'order_id' => 'ord_1']),
        ['authorization' => 'not-the-secret'],
    );

    expect($event->verified)->toBeFalse()
        ->and($event->status)->toBe(PaymentStatus::Pending);
});

it('is resolvable through the factory', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(testCredentials()));

    expect($factory->make(GatewayName::Tamara, testCredentials()))->toBeInstanceOf(TamaraGateway::class);
});
