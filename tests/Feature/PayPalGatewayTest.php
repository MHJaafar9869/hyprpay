<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalPaymentMethodPreference;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalShippingPreference;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalUserAction;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\PayPalCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\PayPalGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * PayPal sandbox credentials: the client id is the merchant id, the client secret the
 * shared secret, and the webhook id the webhook secret used for signature verification.
 */
function paypalCredentials(): GatewayCredentials
{
    return new GatewayCredentials(
        host: 'api-m.sandbox.paypal.com',
        merchantId: 'CLIENT_ID',
        apiKeyId: '',
        sharedSecret: 'CLIENT_SECRET',
        testMode: true,
        webhookSecret: 'WEBHOOK_ID',
        currency: 'USD',
    );
}

/**
 * A fake HTTP client pre-seeded with the OAuth token response every call authenticates with.
 */
function paypalHttp(): FakeHttpClient
{
    return (new FakeHttpClient)->queueJson(['access_token' => 'A21_TOKEN', 'token_type' => 'Bearer', 'expires_in' => 32400]);
}

/**
 * Decode the most recent request body sent through the fake client.
 *
 * @return array<string, mixed>
 */
function paypalBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('creates an order and returns the approval redirect URL', function (): void {
    $http = paypalHttp()->queueJson([
        'id' => 'ORDER123',
        'status' => 'PAYER_ACTION_REQUIRED',
        'purchase_units' => [['custom_id' => 'ORD1']],
        'links' => [
            ['href' => 'https://api.sandbox.paypal.com/v2/checkout/orders/ORDER123', 'rel' => 'self', 'method' => 'GET'],
            ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123', 'rel' => 'payer-action', 'method' => 'GET'],
        ],
    ]);

    $session = (new PayPalGateway(paypalCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
        returnUrl: 'https://shop.test/return',
        description: 'Gold Plan',
    ));

    $token = $http->recorded()[0];

    expect($session->redirectUrl)->toBe('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123')
        ->and($session->reference)->toBe('ORDER123')
        ->and($session->merchantReference)->toBe('ORD1')
        ->and($token->url)->toBe('https://api-m.sandbox.paypal.com/v1/oauth2/token')
        ->and($token->header('Authorization'))->toBe('Basic '.base64_encode('CLIENT_ID:CLIENT_SECRET'))
        ->and($token->body)->toBe('grant_type=client_credentials')
        ->and($http->lastRequest()?->url)->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders')
        ->and($http->lastRequest()?->header('Authorization'))->toBe('Bearer A21_TOKEN')
        ->and($http->lastRequest()?->header('PayPal-Request-Id'))->toBe('ORD1')
        ->and(paypalBody($http)['intent'])->toBe('CAPTURE')
        ->and(paypalBody($http)['purchase_units'][0]['amount'])->toBe(['currency_code' => 'USD', 'value' => '100.00'])
        ->and(paypalBody($http)['purchase_units'][0]['custom_id'])->toBe('ORD1')
        ->and(paypalBody($http)['payment_source']['paypal']['experience_context']['return_url'])->toBe('https://shop.test/return')
        ->and(paypalBody($http)['payment_source']['paypal']['experience_context']['cancel_url'])->toBe('https://shop.test/return')
        ->and(paypalBody($http)['payment_source']['paypal']['experience_context']['user_action'])->toBe('PAY_NOW')
        ->and(paypalBody($http)['payment_source']['paypal']['experience_context']['shipping_preference'])->toBe('NO_SHIPPING');
});

it('applies typed PayPalCheckoutOptions to the experience context', function (): void {
    $http = paypalHttp()->queueJson(['id' => 'ORDER123', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => []]);

    (new PayPalGateway(paypalCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
        returnUrl: 'https://shop.test/return',
        options: new PayPalCheckoutOptions(
            cancelUrl: 'https://shop.test/cancel',
            brandName: 'Example Store',
            locale: 'en-GB',
            shippingPreference: PayPalShippingPreference::SetProvidedAddress,
            userAction: PayPalUserAction::Continue_,
            paymentMethodPreference: PayPalPaymentMethodPreference::ImmediatePaymentRequired,
        ),
    ));

    $context = paypalBody($http)['payment_source']['paypal']['experience_context'];

    expect($context['return_url'])->toBe('https://shop.test/return')
        ->and($context['cancel_url'])->toBe('https://shop.test/cancel')
        ->and($context['brand_name'])->toBe('Example Store')
        ->and($context['locale'])->toBe('en-GB')
        ->and($context['shipping_preference'])->toBe('SET_PROVIDED_ADDRESS')
        ->and($context['user_action'])->toBe('CONTINUE')
        ->and($context['payment_method_preference'])->toBe('IMMEDIATE_PAYMENT_REQUIRED');
});

it('builds options from a raw config array via fromArray', function (): void {
    $http = paypalHttp()->queueJson(['id' => 'ORDER123', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => []]);

    (new PayPalGateway(paypalCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'USD'),
        returnUrl: 'https://shop.test/return',
        options: PayPalCheckoutOptions::fromArray(['brand_name' => 'Legacy Store', 'user_action' => 'CONTINUE']),
    ));

    $context = paypalBody($http)['payment_source']['paypal']['experience_context'];

    expect($context['brand_name'])->toBe('Legacy Store')
        ->and($context['user_action'])->toBe('CONTINUE');
});

it('creates an authorize-intent order when the payment method is authorize', function (): void {
    $http = paypalHttp()->queueJson(['id' => 'ORDER9', 'status' => 'CREATED', 'links' => []]);

    (new PayPalGateway(paypalCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(5000, 'USD'),
        orderReference: 'ORD9',
        returnUrl: 'https://shop.test/return',
        paymentMethod: 'authorize',
    ));

    expect(paypalBody($http)['intent'])->toBe('AUTHORIZE');
});

it('captures an approved order via charge', function (): void {
    $http = paypalHttp()->queueJson([
        'id' => 'ORDER123',
        'status' => 'COMPLETED',
        'purchase_units' => [['payments' => ['captures' => [
            ['id' => 'CAPTURE1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '100.00']],
        ]]]],
    ]);

    $result = (new PayPalGateway(paypalCredentials(), $http))->charge(new ChargeRequest(
        transientToken: 'ORDER123',
        money: Money::minor(10000, 'USD'),
        orderReference: 'ORD1',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('CAPTURE1')
        ->and($http->lastRequest()?->url)->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123/capture')
        ->and($http->lastRequest()?->method)->toBe('POST');
});

it('authorizes an approved order when charge does not capture', function (): void {
    $http = paypalHttp()->queueJson([
        'id' => 'ORDER123',
        'status' => 'COMPLETED',
        'purchase_units' => [['payments' => ['authorizations' => [
            ['id' => 'AUTH1', 'status' => 'CREATED', 'amount' => ['currency_code' => 'USD', 'value' => '100.00']],
        ]]]],
    ]);

    $result = (new PayPalGateway(paypalCredentials(), $http))->charge(new ChargeRequest(
        transientToken: 'ORDER123',
        money: Money::minor(10000, 'USD'),
        capture: false,
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Authorized)
        ->and($result->transactionId)->toBe('AUTH1')
        ->and($http->lastRequest()?->url)->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123/authorize');
});

it('captures an authorized payment', function (): void {
    $http = paypalHttp()->queueJson([
        'id' => 'CAPTURE2',
        'status' => 'COMPLETED',
        'amount' => ['currency_code' => 'USD', 'value' => '60.00'],
    ]);

    $result = (new PayPalGateway(paypalCredentials(), $http))->capture(new CaptureRequest(
        transactionId: 'AUTH1',
        money: Money::minor(6000, 'USD'),
        orderReference: 'ORD1',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('CAPTURE2')
        ->and($http->lastRequest()?->url)->toBe('https://api-m.sandbox.paypal.com/v2/payments/authorizations/AUTH1/capture')
        ->and(paypalBody($http)['amount'])->toBe(['currency_code' => 'USD', 'value' => '60.00'])
        ->and(paypalBody($http)['final_capture'])->toBeTrue();
});

it('refunds a captured payment', function (): void {
    $http = paypalHttp()->queueJson([
        'id' => 'REFUND1',
        'status' => 'COMPLETED',
        'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
    ]);

    $result = (new PayPalGateway(paypalCredentials(), $http))->refund(new RefundRequest(
        transactionId: 'CAPTURE1',
        money: Money::minor(1000, 'USD'),
        reason: 'Defective product',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->refundId)->toBe('REFUND1')
        ->and($http->lastRequest()?->url)->toBe('https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE1/refund')
        ->and(paypalBody($http)['amount'])->toBe(['currency_code' => 'USD', 'value' => '10.00'])
        ->and(paypalBody($http)['note_to_payer'])->toBe('Defective product');
});

it('voids an authorized payment', function (): void {
    $http = paypalHttp();

    $result = (new PayPalGateway(paypalCredentials(), $http))->void(new VoidRequest(transactionId: 'AUTH1'));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Voided)
        ->and($result->transactionId)->toBe('AUTH1')
        ->and($http->lastRequest()?->url)->toBe('https://api-m.sandbox.paypal.com/v2/payments/authorizations/AUTH1/void')
        ->and($http->lastRequest()?->method)->toBe('POST');
});

it('looks up an order and maps its status, amount, and reference', function (): void {
    $http = paypalHttp()->queueJson([
        'id' => 'ORDER123',
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'custom_id' => 'ORD1',
            'amount' => ['currency_code' => 'USD', 'value' => '100.00'],
        ]],
    ]);

    $snapshot = (new PayPalGateway(paypalCredentials(), $http))->getTransaction('ORDER123');

    expect($snapshot->status)->toBe(PaymentStatus::Captured)
        ->and($snapshot->transactionId)->toBe('ORDER123')
        ->and($snapshot->orderReference)->toBe('ORD1')
        ->and($snapshot->money?->toDecimalString())->toBe('100.00')
        ->and($snapshot->money?->currency)->toBe('USD')
        ->and($http->lastRequest()?->url)->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123')
        ->and($http->lastRequest()?->method)->toBe('GET');
});

it('maps a pending order status', function (): void {
    $http = paypalHttp()->queueJson(['id' => 'ORDER123', 'status' => 'APPROVED']);

    $snapshot = (new PayPalGateway(paypalCredentials(), $http))->getTransaction('ORDER123');

    expect($snapshot->status)->toBe(PaymentStatus::Pending);
});

it('vaults a card via the setup-token then payment-token flow', function (): void {
    $http = paypalHttp()
        ->queueJson(['id' => 'SETUP-TOKEN-1', 'status' => 'CREATED'])
        ->queueJson(['id' => 'PAYMENT-TOKEN-1', 'customer' => ['id' => 'CUST-9']]);

    $vaulted = (new PayPalGateway(paypalCredentials(), $http))->vaultInstrument(new TokenizeInstrumentRequest(
        cardNumber: '4111111111111111',
        expirationMonth: '02',
        expirationYear: '2027',
        billTo: new BillingAddress(firstName: 'John', lastName: 'Doe', address1: '1 Main St', locality: 'San Jose', administrativeArea: 'CA', postalCode: '95131', country: 'US'),
    ));

    $requests = $http->recorded();

    expect($vaulted->success)->toBeTrue()
        ->and($vaulted->paymentInstrumentId)->toBe('PAYMENT-TOKEN-1')
        ->and($vaulted->customerId)->toBe('CUST-9')
        ->and($requests[1]->url)->toBe('https://api-m.sandbox.paypal.com/v3/vault/setup-tokens')
        ->and($requests[2]->url)->toBe('https://api-m.sandbox.paypal.com/v3/vault/payment-tokens')
        ->and(json_decode((string) $requests[1]->body, true)['payment_source']['card']['expiry'])->toBe('2027-02')
        ->and(json_decode((string) $requests[2]->body, true)['payment_source']['token'])->toBe(['id' => 'SETUP-TOKEN-1', 'type' => 'SETUP_TOKEN']);
});

it('charges a vaulted card as a merchant-initiated stored credential', function (): void {
    $http = paypalHttp()
        ->queueJson(['id' => 'ORDER-SC', 'status' => 'CREATED'])
        ->queueJson([
            'id' => 'ORDER-SC',
            'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-SC', 'status' => 'COMPLETED']]]]],
        ]);

    $result = (new PayPalGateway(paypalCredentials(), $http))->chargeStoredCredential(new StoredCredentialChargeRequest(
        paymentInstrumentId: 'PAYMENT-TOKEN-1',
        money: Money::minor(50000, 'USD'),
        initiator: CredentialInitiator::Merchant,
        orderReference: 'ORD-SUB-1',
    ));

    $requests = $http->recorded();
    $orderBody = json_decode((string) $requests[1]->body, true);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('CAP-SC')
        ->and($requests[1]->url)->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders')
        ->and($requests[2]->url)->toBe('https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-SC/capture')
        ->and($orderBody['payment_source']['card']['vault_id'])->toBe('PAYMENT-TOKEN-1')
        ->and($orderBody['payment_source']['card']['stored_credential']['payment_initiator'])->toBe('MERCHANT')
        ->and($orderBody['payment_source']['card']['stored_credential']['payment_type'])->toBe('RECURRING');
});

it('uses an inline-completed stored-credential order without a second capture', function (): void {
    $http = paypalHttp()->queueJson([
        'id' => 'ORDER-SC',
        'status' => 'COMPLETED',
        'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-INLINE', 'status' => 'COMPLETED']]]]],
    ]);

    $result = (new PayPalGateway(paypalCredentials(), $http))->chargeStoredCredential(new StoredCredentialChargeRequest(
        paymentInstrumentId: 'PAYMENT-TOKEN-1',
        money: Money::minor(1000, 'USD'),
        initiator: CredentialInitiator::Customer,
    ));

    expect($result->transactionId)->toBe('CAP-INLINE')
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($http->requestCount())->toBe(2);
});

it('verifies a genuine webhook signature through the API', function (): void {
    $http = paypalHttp()->queueJson(['verification_status' => 'SUCCESS']);
    $event = json_encode([
        'id' => 'WH-EVENT-1',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => ['id' => 'CAPTURE1', 'status' => 'COMPLETED'],
    ]);

    $result = (new PayPalGateway(paypalCredentials(), $http))->verifyWebhook((string) $event, [
        'PayPal-Transmission-Id' => 'txn-1',
        'PayPal-Transmission-Time' => '2026-08-07T00:00:00Z',
        'PayPal-Transmission-Sig' => 'sig',
        'PayPal-Cert-Url' => 'https://api.sandbox.paypal.com/cert.pem',
        'PayPal-Auth-Algo' => 'SHA256withRSA',
    ]);

    expect($result->verified)->toBeTrue()
        ->and($result->eventType)->toBe('PAYMENT.CAPTURE.COMPLETED')
        ->and($result->transactionId)->toBe('CAPTURE1')
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($http->lastRequest()?->url)->toBe('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature')
        ->and(paypalBody($http)['webhook_id'])->toBe('WEBHOOK_ID')
        ->and(paypalBody($http)['transmission_id'])->toBe('txn-1')
        ->and(paypalBody($http)['webhook_event']['id'])->toBe('WH-EVENT-1');
});

it('rejects a webhook the API reports as unverified', function (): void {
    $http = paypalHttp()->queueJson(['verification_status' => 'FAILURE']);
    $event = json_encode(['event_type' => 'PAYMENT.CAPTURE.DENIED', 'resource' => ['id' => 'CAPTURE1', 'status' => 'DECLINED']]);

    $result = (new PayPalGateway(paypalCredentials(), $http))->verifyWebhook((string) $event, []);

    expect($result->verified)->toBeFalse()
        ->and($result->status)->toBe(PaymentStatus::Declined);
});

it('authenticates only once across multiple operations on the same instance', function (): void {
    $http = paypalHttp()
        ->queueJson(['id' => 'ORDER123', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP1', 'status' => 'COMPLETED']]]]]])
        ->queueJson(['id' => 'REFUND1', 'status' => 'COMPLETED']);

    $gateway = new PayPalGateway(paypalCredentials(), $http);
    $gateway->charge(new ChargeRequest(transientToken: 'ORDER123', money: Money::minor(10000, 'USD')));
    $gateway->refund(new RefundRequest(transactionId: 'CAP1', money: Money::minor(1000, 'USD')));

    $tokenCalls = array_filter(
        $http->recorded(),
        static fn (HttpRequest $request): bool => str_ends_with($request->url, '/v1/oauth2/token'),
    );

    expect($tokenCalls)->toHaveCount(1)
        ->and($http->requestCount())->toBe(3);
});

it('is resolvable through the factory', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(paypalCredentials()));

    expect($factory->make(GatewayName::PayPal, paypalCredentials()))->toBeInstanceOf(PayPalGateway::class);
});
