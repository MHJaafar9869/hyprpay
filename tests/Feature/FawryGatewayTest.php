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
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawryCard;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawryCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawryGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawrySignature;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: FawryGateway, 1: FakeHttpClient}
 */
function fawryWithFakeHttp(): array
{
    $http = new FakeHttpClient;

    return [new FawryGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function fawryBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('creates a hosted checkout and returns the redirect URL', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueBody('https://atfawry.fawrystaging.com/checkout/abc');

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        orderReference: 'ORD1',
        returnUrl: 'https://shop.test/return',
    ));

    expect($session->redirectUrl)->toBe('https://atfawry.fawrystaging.com/checkout/abc')
        ->and($session->merchantReference)->toBe('ORD1')
        ->and($http->lastRequest()?->url)->toBe('https://atfawry.fawrystaging.com/fawrypay-api/api/payments/init')
        ->and(fawryBody($http)['signature'])->not->toBeEmpty()
        ->and(fawryBody($http)['chargeItems'][0]['price'])->toBe('100.00');
});

it('creates a pay-at-outlet payment and returns the reference number', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 200, 'referenceNumber' => '9900001111']);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        orderReference: 'ORD2',
        paymentMethod: 'PAYATFAWRY',
    ));

    expect($session->reference)->toBe('9900001111')
        ->and($http->lastRequest()?->url)->toBe('https://atfawry.fawrystaging.com/ECommerceWeb/Fawry/payments/charge')
        ->and(fawryBody($http)['paymentMethod'])->toBe('PAYATFAWRY');
});

it('creates a mobile-wallet payment and returns the reference and QR', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 200, 'referenceNumber' => '888', 'walletQr' => 'QR_DATA']);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(5000, 'EGP'),
        orderReference: 'ORD3',
        paymentMethod: 'MWALLET',
        options: new FawryCheckoutOptions(walletNumber: '01000000000'),
    ));

    expect($session->reference)->toBe('888')
        ->and($session->qrCode)->toBe('QR_DATA')
        ->and(fawryBody($http)['debitMobileWalletNo'])->toBe('01000000000');
});

it('creates a card payment and returns the 3DS redirect URL', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 200, 'nextAction' => ['redirectUrl' => 'https://acs.test/3ds']]);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        orderReference: 'ORD4',
        returnUrl: 'https://shop.test/return',
        paymentMethod: 'PayUsingCC',
        options: new FawryCheckoutOptions(card: new FawryCard('4111111111111111', '30', '12', '123')),
    ));

    expect($session->redirectUrl)->toBe('https://acs.test/3ds')
        ->and(fawryBody($http)['cardNumber'])->toBe('4111111111111111')
        ->and(fawryBody($http)['enable3DS'])->toBeTrue();
});

it('refunds a settled payment by its reference number', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 200, 'statusDescription' => 'Operation done successfully']);

    $result = $gateway->refund(new RefundRequest(transactionId: 'FRN-1', money: Money::minor(2500, 'EGP'), reason: 'duplicate'));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->refundId)->toBe('FRN-1')
        ->and($http->lastRequest()?->url)->toBe('https://atfawry.fawrystaging.com/ECommerceWeb/Fawry/payments/refund')
        ->and(fawryBody($http)['referenceNumber'])->toBe('FRN-1')
        ->and(fawryBody($http)['reason'])->toBe('duplicate');
});

it('captures an authorization and maps it to a captured result', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 200, 'statusDescription' => 'Operation done successfully']);

    $result = $gateway->capture(new CaptureRequest(transactionId: 'ORD8', money: Money::minor(7500, 'EGP')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('ORD8')
        ->and($http->lastRequest()?->url)->toBe('https://atfawry.fawrystaging.com/ECommerceWeb/api/payment/capture')
        ->and(fawryBody($http)['merchantCode'])->toBe('test_merchant')
        ->and(fawryBody($http)['merchantRefNum'])->toBe('ORD8')
        ->and(fawryBody($http)['captureAmount'])->toBe('75.00')
        ->and(fawryBody($http)['requestSignature'])
        ->toBe(FawrySignature::capture('ORD8', '75.00', 'test_merchant', testCredentials()->sharedSecret));
});

it('cancels an authorization and maps it to a voided result', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 200, 'statusDescription' => 'Operation done successfully']);

    $result = $gateway->void(new VoidRequest(transactionId: 'ORD8'));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Voided)
        ->and($result->transactionId)->toBe('ORD8')
        ->and($http->lastRequest()?->url)->toBe('https://atfawry.fawrystaging.com/ECommerceWeb/api/payment/cancel')
        ->and(fawryBody($http)['merchantCode'])->toBe('test_merchant')
        ->and(fawryBody($http)['merchantRefNum'])->toBe('ORD8')
        ->and(fawryBody($http)['requestSignature'])
        ->toBe(FawrySignature::cancelAuthorization('ORD8', 'test_merchant', testCredentials()->sharedSecret));
});

it('prefers the order reference over the transaction id when cancelling', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 200]);

    $gateway->void(new VoidRequest(transactionId: 'FRN-2', orderReference: 'ORD-CANCEL'));

    expect(fawryBody($http)['merchantRefNum'])->toBe('ORD-CANCEL');
});

it('reports a failed void when Fawry rejects the cancellation', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 9946, 'statusDescription' => 'Authorization already captured']);

    $result = $gateway->void(new VoidRequest(transactionId: 'ORD9'));

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->code)->toBe('9946');
});

it('creates a MyFawry payment and returns the deep link and QR', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson([
        'statusCode' => 200,
        'referenceNumber' => '9900002222',
        'deepLink' => 'myfawry://pay/9900002222',
        'qrCode' => 'https://atfawry.test/qr/9900002222',
    ]);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        orderReference: 'ORD-MF',
        paymentMethod: 'MYFAWRY',
    ));

    expect($session->reference)->toBe('9900002222')
        ->and($session->redirectUrl)->toBe('myfawry://pay/9900002222')
        ->and($session->qrCode)->toBe('https://atfawry.test/qr/9900002222')
        ->and($http->lastRequest()?->url)->toBe('https://atfawry.fawrystaging.com/ECommerceWeb/Fawry/payments/charge')
        ->and(fawryBody($http)['paymentMethod'])->toBe('MYFAWRY');
});

it('creates a bank instalment card payment and returns the 3DS redirect URL', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 200, 'nextAction' => ['redirectUrl' => 'https://acs.test/3ds']]);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(120000, 'EGP'),
        orderReference: 'ORD-INST',
        paymentMethod: 'CARD',
        options: new FawryCheckoutOptions(
            card: new FawryCard('4111111111111111', '30', '12', '123'),
            installmentPlanId: 'PLAN-12',
        ),
    ));

    expect($session->redirectUrl)->toBe('https://acs.test/3ds')
        ->and(fawryBody($http)['paymentMethod'])->toBe('CARD')
        ->and(fawryBody($http)['installmentPlanId'])->toBe('PLAN-12')
        ->and(fawryBody($http)['cardNumber'])->toBe('4111111111111111')
        ->and(fawryBody($http)['signature'])->toBe(FawrySignature::installmentCard(
            'test_merchant',
            'ORD-INST',
            null,
            'CARD',
            '1200.00',
            '4111111111111111',
            '30',
            '12',
            '123',
            'PLAN-12',
            testCredentials()->sharedSecret,
        ));
});

it('looks up a transaction status via a signed GET request', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['orderStatus' => 'PAID', 'fawryRefNumber' => 'F-123', 'merchantRefNumber' => 'ORD5']);

    $snapshot = $gateway->getTransaction('ORD5');
    $request = $http->lastRequest();

    expect($snapshot->status)->toBe(PaymentStatus::Captured)
        ->and($snapshot->transactionId)->toBe('F-123')
        ->and($request?->method)->toBe('GET')
        ->and($request?->url)->toStartWith('https://atfawry.fawrystaging.com/ECommerceWeb/Fawry/payments/status/v2?')
        ->and($request?->url)->toContain('signature=');
});

it('verifies a correctly signed FawryPay webhook', function (): void {
    [$gateway] = fawryWithFakeHttp();
    $secret = base64_encode('test_secret');

    $body = (string) json_encode([
        'fawryRefNumber' => 'F-9',
        'merchantRefNumber' => 'ORD6',
        'paymentAmount' => 100.0,
        'orderAmount' => 100.0,
        'orderStatus' => 'PAID',
        'paymentMethod' => 'PayUsingCC',
        'paymentRefrenceNumber' => 'PR-1',
        'messageSignature' => FawrySignature::webhook('F-9', 'ORD6', '100.00', '100.00', 'PAID', 'PayUsingCC', 'PR-1', $secret),
    ]);

    $event = $gateway->verifyWebhook($body, []);

    expect($event->verified)->toBeTrue()
        ->and($event->status)->toBe(PaymentStatus::Captured)
        ->and($event->transactionId)->toBe('F-9');
});

it('rejects a webhook whose amount was tampered with after signing', function (): void {
    [$gateway] = fawryWithFakeHttp();
    $secret = base64_encode('test_secret');
    $signature = FawrySignature::webhook('F-9', 'ORD6', '100.00', '100.00', 'PAID', 'PayUsingCC', 'PR-1', $secret);

    $body = (string) json_encode([
        'fawryRefNumber' => 'F-9',
        'merchantRefNumber' => 'ORD6',
        'paymentAmount' => 999.0,
        'orderAmount' => 999.0,
        'orderStatus' => 'PAID',
        'paymentMethod' => 'PayUsingCC',
        'paymentRefrenceNumber' => 'PR-1',
        'messageSignature' => $signature,
    ]);

    expect($gateway->verifyWebhook($body, [])->verified)->toBeFalse();
});

it('throws when FawryPay rejects a charge with a non-200 status code', function (): void {
    [$gateway, $http] = fawryWithFakeHttp();
    $http->queueJson(['statusCode' => 9946, 'statusDescription' => 'Invalid merchant']);

    expect(fn (): CheckoutSession => $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        orderReference: 'ORD7',
        paymentMethod: 'PAYATFAWRY',
    )))->toThrow(GatewayRequestException::class);
});

it('is resolvable through the factory', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(testCredentials()));

    expect($factory->make(GatewayName::Fawry, testCredentials()))->toBeInstanceOf(FawryGateway::class);
});
