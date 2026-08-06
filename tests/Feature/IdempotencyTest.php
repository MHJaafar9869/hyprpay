<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawryGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

function cybersourceWith(FakeHttpClient $http): CybersourceUnifiedCheckoutGateway
{
    return new CybersourceUnifiedCheckoutGateway(testCredentials(), $http);
}

it('sends the CyberSource idempotency header from an explicit key', function (): void {
    $http = (new FakeHttpClient)->queueJson(['id' => 't', 'status' => 'AUTHORIZED']);

    cybersourceWith($http)->charge(new ChargeRequest(
        transientToken: 'tok',
        money: Money::minor(1000, 'USD'),
        idempotencyKey: 'idem-123',
    ));

    expect($http->lastRequest()?->header('v-c-idempotency-key'))->toBe('idem-123');
});

it('defaults the CyberSource idempotency key to the order reference', function (): void {
    $http = (new FakeHttpClient)->queueJson(['id' => 't', 'status' => 'AUTHORIZED']);

    cybersourceWith($http)->charge(new ChargeRequest(
        transientToken: 'tok',
        money: Money::minor(1000, 'USD'),
        orderReference: 'ORD-9',
    ));

    expect($http->lastRequest()?->header('v-c-idempotency-key'))->toBe('ORD-9');
});

it('omits the CyberSource idempotency header when no key or order reference is given', function (): void {
    $http = (new FakeHttpClient)->queueJson(['id' => 't', 'status' => 'AUTHORIZED']);

    cybersourceWith($http)->charge(new ChargeRequest(transientToken: 'tok', money: Money::minor(1000, 'USD')));

    expect($http->lastRequest()?->header('v-c-idempotency-key'))->toBeNull();
});

it('builds an identical CyberSource charge body on repeated calls', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['id' => 't', 'status' => 'AUTHORIZED'])
        ->queueJson(['id' => 't', 'status' => 'AUTHORIZED']);
    $gateway = cybersourceWith($http);
    $request = new ChargeRequest(transientToken: 'tok', money: Money::minor(2599, 'USD'), orderReference: 'ORD-1');

    $gateway->charge($request);
    $gateway->charge($request);

    expect($http->recorded()[0]->body)->toBe($http->recorded()[1]->body);
});

it('builds an identical Fawry request with a stable merchant reference on repeated calls', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['statusCode' => 200, 'referenceNumber' => 'r'])
        ->queueJson(['statusCode' => 200, 'referenceNumber' => 'r']);
    $gateway = new FawryGateway(testCredentials(), $http);
    $request = new CheckoutSessionRequest(money: Money::minor(10000, 'EGP'), orderReference: 'ORD-1', paymentMethod: 'PAYATFAWRY');

    $gateway->createCheckoutSession($request);
    $gateway->createCheckoutSession($request);

    expect($http->recorded()[0]->body)->toBe($http->recorded()[1]->body)
        ->and(json_decode((string) $http->recorded()[0]->body, true)['merchantRefNum'])->toBe('ORD-1');
});
