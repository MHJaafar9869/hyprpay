<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Paylink\PaylinkCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\Paylink\PaylinkGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * Credentials whose hash token matches the shared golden fixtures, so the webhook
 * tests can reuse the fixtures' signed webhook payloads.
 */
function paylinkCredentials(): GatewayCredentials
{
    return new GatewayCredentials(
        host: 'pay.getpayin.com',
        merchantId: 'PUBLIC_TOKEN',
        apiKeyId: '',
        sharedSecret: 'test_hash_token_abc123',
        testMode: false,
    );
}

/**
 * @return array<string, mixed>
 */
function paylinkBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('creates an invoice and returns the checkout URL, signing the body', function (): void {
    $http = (new FakeHttpClient)->queueJson(['checkout_url' => 'https://pay.getpayin.com/c/abc', 'invoice_id' => 9001, 'expires_at' => '2026-08-06']);
    $gateway = new PaylinkGateway(paylinkCredentials(), $http);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(25000, 'USD'),
        orderReference: 'ORD1',
        returnUrl: 'https://shop.test/return',
        customer: new Customer(email: 'john@example.com', firstName: 'John', lastName: 'Doe'),
        description: 'Gold Plan',
        options: new PaylinkCheckoutOptions(webhookUrl: 'https://shop.test/webhook'),
    ));

    $request = $http->lastRequest();

    expect($session->redirectUrl)->toBe('https://pay.getpayin.com/c/abc')
        ->and($session->reference)->toBe('9001')
        ->and($request?->url)->toBe('https://pay.getpayin.com/api/v2/integration/init')
        ->and(paylinkBody($http)['token'])->toBe('PUBLIC_TOKEN')
        ->and(paylinkBody($http)['first_name'])->toBe('John')
        ->and(paylinkBody($http)['order_amount'])->toBe('250.00')
        ->and(paylinkBody($http))->toHaveKey('signature')
        ->and($request?->header('Idempotency-Key'))->toBe('ORD1');
});

it('sends an unsigned iframe flag and returns an iframe-ready checkout URL', function (): void {
    $plainHttp = (new FakeHttpClient)->queueJson(['checkout_url' => 'https://pay.getpayin.com/c/abc', 'invoice_id' => 9001]);
    (new PaylinkGateway(paylinkCredentials(), $plainHttp))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(25000, 'USD'),
        orderReference: 'ORD1',
        returnUrl: 'https://shop.test/return',
        description: 'Gold Plan',
    ));

    $iframeHttp = (new FakeHttpClient)->queueJson(['checkout_url' => 'https://pay.getpayin.com/c/abc?iframe=1', 'invoice_id' => 9001]);
    $session = (new PaylinkGateway(paylinkCredentials(), $iframeHttp))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(25000, 'USD'),
        orderReference: 'ORD1',
        returnUrl: 'https://shop.test/return',
        description: 'Gold Plan',
        options: new PaylinkCheckoutOptions(iframe: true),
    ));

    expect(paylinkBody($iframeHttp)['iframe'])->toBe('1')
        ->and(paylinkBody($plainHttp))->not->toHaveKey('iframe')
        ->and(paylinkBody($iframeHttp)['signature'])->toBe(paylinkBody($plainHttp)['signature'])
        ->and($session->redirectUrl)->toBe('https://pay.getpayin.com/c/abc?iframe=1');
});

it('captures (settles) an authorized invoice', function (): void {
    $http = (new FakeHttpClient)->queueJson(['invoice_id' => 9001, 'paid_status' => 'PAID', 'auth_code' => 'A1']);
    $gateway = new PaylinkGateway(paylinkCredentials(), $http);

    $result = $gateway->capture(new CaptureRequest(transactionId: '9001', money: Money::minor(25000, 'USD')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->code)->toBe('A1')
        ->and($http->lastRequest()?->url)->toBe('https://pay.getpayin.com/api/integration/settle');
});

it('refunds an invoice', function (): void {
    $http = (new FakeHttpClient)->queueJson(['invoice_id' => 9001, 'paid_status' => 'REFUNDED', 'refund_amount' => '10.50']);
    $gateway = new PaylinkGateway(paylinkCredentials(), $http);

    $result = $gateway->refund(new RefundRequest(transactionId: '9001', money: Money::minor(1050, 'USD')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and($http->lastRequest()?->url)->toBe('https://pay.getpayin.com/api/integration/refund')
        ->and(paylinkBody($http)['amount'])->toBe('10.50');
});

it('voids an invoice', function (): void {
    $http = (new FakeHttpClient)->queueJson(['invoice_id' => 9001, 'paid_status' => 'VOIDED']);
    $gateway = new PaylinkGateway(paylinkCredentials(), $http);

    $result = $gateway->void(new VoidRequest(transactionId: '9001'));

    expect($result->status)->toBe(PaymentStatus::Voided)
        ->and($http->lastRequest()?->url)->toBe('https://pay.getpayin.com/api/integration/void');
});

it('reverses an authorization', function (): void {
    $http = (new FakeHttpClient)->queueJson(['invoice_id' => 9001, 'paid_status' => 'REVERSED']);
    $gateway = new PaylinkGateway(paylinkCredentials(), $http);

    $result = $gateway->reverseAuthorization(new ReversalRequest(transactionId: '9001', money: Money::minor(25000, 'USD')));

    expect($result->status)->toBe(PaymentStatus::Reversed)
        ->and($http->lastRequest()?->url)->toBe('https://pay.getpayin.com/api/integration/reverse-authorization');
});

it('checks an invoice status', function (): void {
    $http = (new FakeHttpClient)->queueJson(['invoice_id' => 9001, 'paid_status' => 'AUTHORIZED']);
    $gateway = new PaylinkGateway(paylinkCredentials(), $http);

    $snapshot = $gateway->getTransaction('9001');

    expect($snapshot->status)->toBe(PaymentStatus::Authorized)
        ->and($snapshot->transactionId)->toBe('9001')
        ->and($http->lastRequest()?->url)->toBe('https://pay.getpayin.com/api/integration/check-status');
});

it('verifies the golden webhook payloads', function (): void {
    $gateway = new PaylinkGateway(paylinkCredentials(), new FakeHttpClient);
    $verified = 0;

    foreach (goldenFixtures()['cases'] as $case) {
        if ($case['endpoint'] !== 'WEBHOOK') {
            continue;
        }

        $payload = array_merge($case['input'], ['signature' => $case['expected']]);
        expect($gateway->verifyWebhook((string) json_encode($payload), [])->verified)->toBeTrue();
        $verified++;
    }

    expect($verified)->toBeGreaterThanOrEqual(1);
});

it('rejects a tampered paylink webhook', function (): void {
    $gateway = new PaylinkGateway(paylinkCredentials(), new FakeHttpClient);
    $case = collect(goldenFixtures()['cases'])->firstWhere('endpoint', 'WEBHOOK');
    $payload = array_merge($case['input'], ['signature' => $case['expected'], 'invoice_status' => 'UNPAID']);

    expect($gateway->verifyWebhook((string) json_encode($payload), [])->verified)->toBeFalse();
});

it('stores a card and returns the token, signing the ordered body', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'success' => true,
        'data' => ['token' => 'tok_abc', 'card' => ['brand' => 'VISA', 'last4' => '1111'], 'status' => 'active'],
    ], 201);
    $gateway = new PaylinkGateway(paylinkCredentials(), $http);

    $vaulted = $gateway->vaultInstrument(new TokenizeInstrumentRequest(
        cardNumber: '4111111111111111',
        expirationMonth: '05',
        expirationYear: '2027',
        billTo: new BillingAddress(
            firstName: 'Jane',
            lastName: 'Roe',
            email: 'jane@example.com',
            address1: '1 Main St',
            locality: 'Riyadh',
            country: 'SA',
        ),
        customerReference: 'CUST-9',
    ));

    $ordered = ['Jane', 'Roe', 'jane@example.com', 'CUST-9', '4111111111111111', '05', '2027', 'SA', '1 Main St', 'Riyadh'];
    $expectedSignature = base64_encode(hash_hmac('sha256', implode('', $ordered), 'test_hash_token_abc123', true));

    expect($vaulted->success)->toBeTrue()
        ->and($vaulted->paymentInstrumentId)->toBe('tok_abc')
        ->and($http->lastRequest()?->url)->toBe('https://pay.getpayin.com/api/v2/integration/tokens/card')
        ->and(paylinkBody($http)['token'])->toBe('PUBLIC_TOKEN')
        ->and(paylinkBody($http)['card_number'])->toBe('4111111111111111')
        ->and(paylinkBody($http)['customer_reference'])->toBe('CUST-9')
        ->and(paylinkBody($http))->not->toHaveKey('us_state')
        ->and(paylinkBody($http))->not->toHaveKey('postal_code')
        ->and(paylinkBody($http)['signature'])->toBe($expectedSignature);
});

it('sends the state and postal code only for US cards, keeping the signature order', function (): void {
    $http = (new FakeHttpClient)->queueJson(['success' => true, 'data' => ['token' => 'tok_us']], 201);
    $gateway = new PaylinkGateway(paylinkCredentials(), $http);

    $gateway->vaultInstrument(new TokenizeInstrumentRequest(
        cardNumber: '4111111111111111',
        expirationMonth: '05',
        expirationYear: '2027',
        billTo: new BillingAddress(
            firstName: 'John',
            lastName: 'Doe',
            address1: '1 Market St',
            locality: 'San Francisco',
            administrativeArea: 'CA',
            postalCode: '94105',
            country: 'US',
        ),
    ));

    $ordered = ['John', 'Doe', '4111111111111111', '05', '2027', 'US', '1 Market St', 'San Francisco', 'CA', '94105'];
    $expectedSignature = base64_encode(hash_hmac('sha256', implode('', $ordered), 'test_hash_token_abc123', true));

    expect(paylinkBody($http)['us_state'])->toBe('CA')
        ->and(paylinkBody($http)['postal_code'])->toBe('94105')
        ->and(paylinkBody($http))->not->toHaveKey('canada_state')
        ->and(paylinkBody($http)['signature'])->toBe($expectedSignature);
});

it('revokes a stored card token', function (): void {
    $http = (new FakeHttpClient)->queueJson(['success' => true, 'message' => 'Token revoked.']);
    $gateway = new PaylinkGateway(paylinkCredentials(), $http);

    $revoked = $gateway->deleteToken('tok_abc');

    expect($revoked)->toBeTrue()
        ->and($http->lastRequest()?->url)->toBe('https://pay.getpayin.com/api/v2/integration/tokens/revoke')
        ->and(paylinkBody($http)['card_token'])->toBe('tok_abc')
        ->and(paylinkBody($http))->toHaveKey('signature');
});

it('is resolvable through the factory', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(paylinkCredentials()));

    expect($factory->make(GatewayName::Paylink, paylinkCredentials()))->toBeInstanceOf(PaylinkGateway::class);
});
