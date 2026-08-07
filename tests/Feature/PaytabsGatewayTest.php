<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsAgreement;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsBeneficiary;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsSplitPayout;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * PayTabs credentials pointing at the KSA region host, with the server key used for
 * both request auth and callback signature verification.
 */
function paytabsCredentials(): GatewayCredentials
{
    return new GatewayCredentials(
        host: 'secure.paytabs.sa',
        merchantId: '12345',
        apiKeyId: '',
        sharedSecret: 'SERVER_KEY',
        testMode: true,
        webhookSecret: 'SERVER_KEY',
        currency: 'SAR',
    );
}

/**
 * @return array<string, mixed>
 */
function paytabsBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

/**
 * Build a URL-encoded PayTabs callback body signed the way PayTabs signs it.
 *
 * @param  array<string, string>  $fields
 */
function paytabsSignedCallback(array $fields, string $serverKey): string
{
    $signable = array_filter($fields);
    ksort($signable);
    $signature = hash_hmac('sha256', http_build_query($signable), $serverKey);

    return http_build_query($fields + ['signature' => $signature]);
}

it('creates a hosted payment page and returns the redirect URL', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'tran_ref' => 'TST2000',
        'cart_id' => 'ORD1',
        'redirect_url' => 'https://secure.paytabs.sa/payment/wr/ABC',
    ]);
    $gateway = new PaytabsGateway(paytabsCredentials(), $http);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(12030, 'SAR'),
        orderReference: 'ORD1',
        returnUrl: 'https://shop.test/return',
        customer: new Customer(email: 'john@example.com', firstName: 'John', lastName: 'Doe'),
        description: 'Gold Plan',
        options: new PaytabsCheckoutOptions(webhookUrl: 'https://shop.test/ipn'),
    ));

    $request = $http->lastRequest();

    expect($session->redirectUrl)->toBe('https://secure.paytabs.sa/payment/wr/ABC')
        ->and($session->reference)->toBe('TST2000')
        ->and($session->merchantReference)->toBe('ORD1')
        ->and($request?->url)->toBe('https://secure.paytabs.sa/payment/request')
        ->and($request?->header('Authorization'))->toBe('SERVER_KEY')
        ->and(paytabsBody($http)['profile_id'])->toBe(12345)
        ->and(paytabsBody($http)['tran_type'])->toBe('sale')
        ->and(paytabsBody($http)['cart_amount'])->toBe('120.30')
        ->and(paytabsBody($http)['cart_currency'])->toBe('SAR')
        ->and(paytabsBody($http)['callback'])->toBe('https://shop.test/ipn')
        ->and(paytabsBody($http)['customer_details']['name'])->toBe('John Doe');
});

it('sends an auth transaction when the payment method is auth', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST1', 'redirect_url' => 'https://x']);

    (new PaytabsGateway(paytabsCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(1000, 'SAR'),
        orderReference: 'ORD9',
        paymentMethod: 'auth',
    ));

    expect(paytabsBody($http)['tran_type'])->toBe('auth');
});

it('captures an authorization', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'tran_ref' => 'TST2000',
        'cart_id' => 'ORD1',
        'payment_result' => ['response_status' => 'A', 'response_code' => 'G00000', 'response_message' => 'Authorised'],
    ]);
    $gateway = new PaytabsGateway(paytabsCredentials(), $http);

    $result = $gateway->capture(new CaptureRequest(transactionId: 'TST2000', money: Money::minor(12030, 'SAR'), orderReference: 'ORD1'));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('TST2000')
        ->and($result->code)->toBe('G00000')
        ->and(paytabsBody($http)['tran_type'])->toBe('capture')
        ->and(paytabsBody($http)['tran_ref'])->toBe('TST2000')
        ->and($http->lastRequest()?->url)->toBe('https://secure.paytabs.sa/payment/request');
});

it('refunds a settled transaction', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'tran_ref' => 'TST3000',
        'payment_result' => ['response_status' => 'A', 'response_message' => 'Refunded'],
    ]);
    $gateway = new PaytabsGateway(paytabsCredentials(), $http);

    $result = $gateway->refund(new RefundRequest(transactionId: 'TST2000', money: Money::minor(5000, 'SAR')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->refundId)->toBe('TST3000')
        ->and(paytabsBody($http)['tran_type'])->toBe('refund')
        ->and(paytabsBody($http)['cart_amount'])->toBe('50.00');
});

it('voids an uncaptured authorization', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'tran_ref' => 'TST2000',
        'payment_result' => ['response_status' => 'V', 'response_message' => 'Voided'],
    ]);
    $gateway = new PaytabsGateway(paytabsCredentials(), $http);

    $result = $gateway->void(new VoidRequest(transactionId: 'TST2000'));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Voided)
        ->and(paytabsBody($http)['tran_type'])->toBe('void')
        ->and(paytabsBody($http)['tran_ref'])->toBe('TST2000');
});

it('reverses (releases) an authorization hold', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'tran_ref' => 'TST7000',
        'previous_tran_ref' => 'TST2000',
        'payment_result' => ['response_status' => 'A', 'response_message' => 'Released'],
    ]);
    $gateway = new PaytabsGateway(paytabsCredentials(), $http);

    $result = $gateway->reverseAuthorization(new ReversalRequest(transactionId: 'TST2000', money: Money::minor(5000, 'SAR')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Reversed)
        ->and($result->transactionId)->toBe('TST7000')
        ->and(paytabsBody($http)['tran_type'])->toBe('release')
        ->and(paytabsBody($http)['tran_ref'])->toBe('TST2000')
        ->and(paytabsBody($http)['cart_amount'])->toBe('50.00')
        ->and($http->lastRequest()?->url)->toBe('https://secure.paytabs.sa/payment/request');
});

it('queries a transaction and maps its status and amount', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'tran_ref' => 'TST2000',
        'cart_id' => 'ORD1',
        'cart_amount' => '120.30',
        'cart_currency' => 'SAR',
        'payment_result' => ['response_status' => 'A'],
    ]);
    $gateway = new PaytabsGateway(paytabsCredentials(), $http);

    $snapshot = $gateway->getTransaction('TST2000');

    expect($snapshot->status)->toBe(PaymentStatus::Captured)
        ->and($snapshot->transactionId)->toBe('TST2000')
        ->and($snapshot->orderReference)->toBe('ORD1')
        ->and($snapshot->money?->toDecimalString())->toBe('120.30')
        ->and($http->lastRequest()?->url)->toBe('https://secure.paytabs.sa/payment/query')
        ->and(paytabsBody($http)['tran_ref'])->toBe('TST2000');
});

it('maps a hold response to an authorized status', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST2000', 'payment_result' => ['response_status' => 'H']]);

    $snapshot = (new PaytabsGateway(paytabsCredentials(), $http))->getTransaction('TST2000');

    expect($snapshot->status)->toBe(PaymentStatus::Authorized);
});

it('verifies a genuine callback signature', function (): void {
    $gateway = new PaytabsGateway(paytabsCredentials(), new FakeHttpClient);
    $body = paytabsSignedCallback([
        'respStatus' => 'A',
        'tranRef' => 'TST2000',
        'cartId' => 'ORD1',
        'respCode' => 'G00000',
        'respMessage' => 'Authorised',
    ], 'SERVER_KEY');

    $event = $gateway->verifyWebhook($body, []);

    expect($event->verified)->toBeTrue()
        ->and($event->transactionId)->toBe('TST2000')
        ->and($event->status)->toBe(PaymentStatus::Captured);
});

it('rejects a tampered callback', function (): void {
    $gateway = new PaytabsGateway(paytabsCredentials(), new FakeHttpClient);
    $signable = ['respStatus' => 'A', 'tranRef' => 'TST2000', 'cartId' => 'ORD1'];
    ksort($signable);
    $signature = hash_hmac('sha256', http_build_query($signable), 'SERVER_KEY');
    $tampered = http_build_query(['respStatus' => 'D', 'tranRef' => 'TST2000', 'cartId' => 'ORD1', 'signature' => $signature]);

    expect($gateway->verifyWebhook($tampered, [])->verified)->toBeFalse();
});

it('creates an invoice and returns the invoice link', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'invoice_id' => 2072841,
        'invoice_link' => 'https://secure.paytabs.sa/payment/request/invoice/2072841/ABC',
    ]);

    $session = (new PaytabsGateway(paytabsCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(20000, 'SAR'),
        orderReference: 'ORD-INV',
        paymentMethod: 'invoice',
    ));

    expect($session->redirectUrl)->toBe('https://secure.paytabs.sa/payment/request/invoice/2072841/ABC')
        ->and($session->reference)->toBe('2072841')
        ->and(paytabsBody($http)['invoice']['line_items'][0]['unit_cost'])->toBe('200.00')
        ->and($http->lastRequest()?->url)->toBe('https://secure.paytabs.sa/payment/request');
});

it('sends a framed managed-form checkout', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST1', 'redirect_url' => 'https://secure.paytabs.sa/payment/page/ABC']);

    (new PaytabsGateway(paytabsCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(1000, 'SAR'),
        orderReference: 'ORD-MF',
        paymentMethod: 'managed',
    ));

    expect(paytabsBody($http)['framed'])->toBeTrue();
});

it('embeds the hosted page in an iframe when the iframe option is set', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST1', 'redirect_url' => 'https://secure.paytabs.sa/payment/page/ABC']);

    $session = (new PaytabsGateway(paytabsCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(1000, 'SAR'),
        orderReference: 'ORD-IF',
        returnUrl: 'https://shop.test/return',
        options: new PaytabsCheckoutOptions(iframe: true, framedReturnTop: true, framedMessageTarget: 'https://shop.test'),
    ));

    $body = paytabsBody($http);

    expect($body['framed'])->toBeTrue()
        ->and($body['framed_return_top'])->toBeTrue()
        ->and($body['framed_message_target'])->toBe('https://shop.test')
        ->and($session->redirectUrl)->toBe('https://secure.paytabs.sa/payment/page/ABC');
});

it('does not add framed fields to a normal hosted checkout', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST1', 'redirect_url' => 'https://x']);

    (new PaytabsGateway(paytabsCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(1000, 'SAR'),
        orderReference: 'ORD-H',
    ));

    expect(paytabsBody($http))->not->toHaveKey('framed');
});

it('creates a paylink and returns the link URL', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'link_id' => 576768,
        'link_url' => 'https://secure.paytabs.sa/payment/link/12345/576768',
    ]);

    $session = (new PaytabsGateway(paytabsCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(20000, 'SAR'),
        orderReference: 'ORD-PL',
        paymentMethod: 'paylink',
        description: 'Gold Plan',
    ));

    expect($session->redirectUrl)->toBe('https://secure.paytabs.sa/payment/link/12345/576768')
        ->and($session->reference)->toBe('576768')
        ->and(paytabsBody($http)['link_title'])->toBe('Gold Plan')
        ->and(paytabsBody($http)['link_status'])->toBeTrue()
        ->and($http->lastRequest()?->url)->toBe('https://secure.paytabs.sa/payment/link/create');
});

it('requests a card token when tokenise is set', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST1', 'redirect_url' => 'https://x']);

    (new PaytabsGateway(paytabsCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(1000, 'SAR'),
        orderReference: 'ORD-TK',
        options: new PaytabsCheckoutOptions(tokenise: 2),
    ));

    expect(paytabsBody($http)['tokenise'])->toBe(2);
});

it('starts a repeat-billing agreement on checkout', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST1', 'redirect_url' => 'https://secure.paytabs.sa/payment/page/ABC']);

    (new PaytabsGateway(paytabsCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(1230, 'SAR'),
        orderReference: 'ORD-RB',
        options: new PaytabsCheckoutOptions(agreement: new PaytabsAgreement(
            description: 'Monthly plan',
            repeatAmount: 100,
            repeatTerms: 3,
            repeatPeriod: 1,
            repeatEvery: 1,
            firstInstallmentDueDate: '05/May/2026',
        )),
    ));

    $agreement = paytabsBody($http)['agreement'];

    expect($agreement['agreement_description'])->toBe('Monthly plan')
        ->and($agreement['agreement_currency'])->toBe('SAR')
        ->and($agreement['initial_amount'])->toBe('12.30')
        ->and($agreement['repeat_amount'])->toBe(100);
});

it('splits the settled funds across beneficiaries', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST1', 'redirect_url' => 'https://secure.paytabs.sa/payment/page/ABC']);

    (new PaytabsGateway(paytabsCredentials(), $http))->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(20000, 'SAR'),
        orderReference: 'ORD-SPLIT',
        options: new PaytabsCheckoutOptions(splitPayout: [
            new PaytabsSplitPayout(entityId: 1001, entityName: 'Agency', itemDescription: 'Fee', itemTotal: '50.00', mscFlag: 'F', beneficiary: new PaytabsBeneficiary(name: 'Tywin Lannister', accountNumber: 'SA0000000000000000000000', country: 'SA', bank: 'Iron Bank')),
            new PaytabsSplitPayout(entityId: 1002, entityName: 'Vendor', itemDescription: 'Goods', itemTotal: '150.00', mscFlag: 'Z', beneficiary: new PaytabsBeneficiary(name: 'Jon Snow', accountNumber: 'SA1111111111111111111111', country: 'SA', bank: 'Iron Bank')),
        ]),
    ));

    $split = paytabsBody($http)['split_payout'];

    expect($split)->toHaveCount(2)
        ->and($split[0]['entity_name'])->toBe('Agency')
        ->and($split[0]['item_total'])->toBe('50.00')
        ->and($split[1]['beneficiary']['name'])->toBe('Jon Snow');
});

it('charges an own-form payment token', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'tran_ref' => 'TST5000',
        'payment_result' => ['response_status' => 'A', 'response_code' => 'G00000', 'response_message' => 'Authorised'],
    ]);

    $result = (new PaytabsGateway(paytabsCredentials(), $http))->charge(new ChargeRequest(
        transientToken: 'PT_TOKEN',
        money: Money::minor(9500, 'SAR'),
        orderReference: 'ORD-OF',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('TST5000')
        ->and(paytabsBody($http)['payment_token'])->toBe('PT_TOKEN')
        ->and(paytabsBody($http)['tran_type'])->toBe('sale');
});

it('returns pending with a redirect for a 3-D Secure own-form charge', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST5001', 'redirect_url' => 'https://secure.paytabs.sa/payment/3ds/ABC']);

    $result = (new PaytabsGateway(paytabsCredentials(), $http))->charge(new ChargeRequest(
        transientToken: 'PT_TOKEN',
        money: Money::minor(9500, 'SAR'),
        orderReference: 'ORD-3DS',
    ));

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->success)->toBeTrue()
        ->and($result->raw['redirect_url'])->toBe('https://secure.paytabs.sa/payment/3ds/ABC');
});

it('charges a saved token as a recurring merchant-initiated transaction', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'tran_ref' => 'TST6000',
        'payment_result' => ['response_status' => 'A', 'response_message' => 'Authorised'],
    ]);

    $result = (new PaytabsGateway(paytabsCredentials(), $http))->chargeStoredCredential(new StoredCredentialChargeRequest(
        paymentInstrumentId: '2C4652BD67A3EF30C6B390F9668175B9',
        money: Money::minor(50000, 'SAR'),
        initiator: CredentialInitiator::Merchant,
        orderReference: 'ORD-SUB-1',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and(paytabsBody($http)['token'])->toBe('2C4652BD67A3EF30C6B390F9668175B9')
        ->and(paytabsBody($http)['tran_class'])->toBe('recurring');
});

it('charges a saved token as an ecom customer-initiated transaction', function (): void {
    $http = (new FakeHttpClient)->queueJson(['tran_ref' => 'TST6001', 'payment_result' => ['response_status' => 'A']]);

    (new PaytabsGateway(paytabsCredentials(), $http))->chargeStoredCredential(new StoredCredentialChargeRequest(
        paymentInstrumentId: 'TOKEN',
        money: Money::minor(1000, 'SAR'),
        initiator: CredentialInitiator::Customer,
    ));

    expect(paytabsBody($http)['tran_class'])->toBe('ecom');
});

it('deletes a saved token', function (): void {
    $http = (new FakeHttpClient)->queueJson(['code' => 0, 'message' => 'success']);

    $ok = (new PaytabsGateway(paytabsCredentials(), $http))->deleteToken('2C4651BF67A3EC34C6B691F8638B75BC');

    expect($ok)->toBeTrue()
        ->and(paytabsBody($http)['token'])->toBe('2C4651BF67A3EC34C6B691F8638B75BC')
        ->and($http->lastRequest()?->url)->toBe('https://secure.paytabs.sa/payment/token/delete');
});

it('is resolvable through the factory', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(paytabsCredentials()));

    expect($factory->make(GatewayName::Paytabs, paytabsCredentials()))->toBeInstanceOf(PaytabsGateway::class);
});
