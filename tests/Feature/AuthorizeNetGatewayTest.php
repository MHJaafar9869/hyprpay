<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\AuthorizeNet\AuthorizeNetGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * Authorize.Net sandbox credentials: the merchant id is the API Login ID, the shared secret
 * the Transaction Key, and the webhook secret the Signature Key used to verify notifications.
 */
function authorizeNetCredentials(): GatewayCredentials
{
    return new GatewayCredentials(
        host: 'apitest.authorize.net',
        merchantId: 'LOGIN_ID',
        apiKeyId: '',
        sharedSecret: 'TRANSACTION_KEY',
        testMode: true,
        webhookSecret: 'SIGNATURE_KEY',
        currency: 'USD',
    );
}

function authorizeNetGateway(FakeHttpClient $http): AuthorizeNetGateway
{
    return new AuthorizeNetGateway(authorizeNetCredentials(), $http);
}

/**
 * Decode the most recent request body sent through the fake client.
 *
 * @return array<string, mixed>
 */
function authorizeNetBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

/**
 * An approved createTransaction response (unwrapped, as the JSON API returns it).
 *
 * @return array<string, mixed>
 */
function authorizeNetApproved(string $transId = '40000000001'): array
{
    return [
        'messages' => ['resultCode' => 'Ok', 'message' => [['code' => 'I00001', 'text' => 'Successful.']]],
        'transactionResponse' => [
            'responseCode' => '1',
            'authCode' => 'ABC123',
            'transId' => $transId,
            'messages' => [['code' => '1', 'description' => 'This transaction has been approved.']],
        ],
    ];
}

it('charges an Accept.js opaque-data token and captures immediately', function (): void {
    $http = (new FakeHttpClient)->queueJson(authorizeNetApproved());

    $result = authorizeNetGateway($http)->charge(new ChargeRequest(
        transientToken: 'OPAQUE_NONCE',
        money: Money::minor(5000, 'USD'),
        orderReference: 'ORD1',
        billTo: new BillingAddress(
            firstName: 'John',
            lastName: 'Doe',
            address1: '123 Main St',
            locality: 'Bellevue',
            administrativeArea: 'WA',
            postalCode: '98004',
            country: 'US',
        ),
        customer: new Customer(email: 'buyer@example.com'),
    ));

    $tr = authorizeNetBody($http)['createTransactionRequest']['transactionRequest'];

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('40000000001')
        ->and($result->message)->toBe('This transaction has been approved.')
        ->and(authorizeNetBody($http)['createTransactionRequest']['merchantAuthentication'])->toBe([
            'name' => 'LOGIN_ID',
            'transactionKey' => 'TRANSACTION_KEY',
        ])
        ->and($tr['transactionType'])->toBe('authCaptureTransaction')
        ->and($tr['amount'])->toBe('50.00')
        ->and($tr['payment']['opaqueData'])->toBe([
            'dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
            'dataValue' => 'OPAQUE_NONCE',
        ])
        ->and($tr['order']['invoiceNumber'])->toBe('ORD1')
        ->and($tr['customer']['email'])->toBe('buyer@example.com')
        ->and($tr['billTo']['zip'])->toBe('98004')
        ->and(array_keys($tr))->toBe(['transactionType', 'amount', 'payment', 'order', 'customer', 'billTo'])
        ->and($http->lastRequest()?->url)->toBe('https://apitest.authorize.net/xml/v1/request.api');
});

it('authorizes only when capture is disabled', function (): void {
    $http = (new FakeHttpClient)->queueJson(authorizeNetApproved());

    $result = authorizeNetGateway($http)->charge(new ChargeRequest(
        transientToken: 'OPAQUE_NONCE',
        money: Money::minor(5000, 'USD'),
        capture: false,
    ));

    expect($result->status)->toBe(PaymentStatus::Authorized)
        ->and(authorizeNetBody($http)['createTransactionRequest']['transactionRequest']['transactionType'])
        ->toBe('authOnlyTransaction');
});

it('maps a declined charge to a failed result', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'messages' => ['resultCode' => 'Ok'],
        'transactionResponse' => [
            'responseCode' => '2',
            'transId' => '0',
            'errors' => [['errorCode' => '2', 'errorText' => 'This transaction has been declined.']],
        ],
    ]);

    $result = authorizeNetGateway($http)->charge(new ChargeRequest(
        transientToken: 'OPAQUE_NONCE',
        money: Money::minor(5000, 'USD'),
    ));

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(PaymentStatus::Declined)
        ->and($result->transactionId)->toBeNull()
        ->and($result->message)->toBe('This transaction has been declined.');
});

it('strips the UTF-8 BOM Authorize.Net prepends before decoding', function (): void {
    $http = (new FakeHttpClient)->queueBody("\xEF\xBB\xBF".json_encode(authorizeNetApproved()));

    $result = authorizeNetGateway($http)->charge(new ChargeRequest(
        transientToken: 'OPAQUE_NONCE',
        money: Money::minor(5000, 'USD'),
    ));

    expect($result->success)->toBeTrue()
        ->and($result->transactionId)->toBe('40000000001');
});

it('captures a prior authorization by transaction id', function (): void {
    $http = (new FakeHttpClient)->queueJson(authorizeNetApproved('40000000002'));

    $result = authorizeNetGateway($http)->capture(new CaptureRequest(
        transactionId: '40000000001',
        money: Money::minor(5000, 'USD'),
    ));

    $tr = authorizeNetBody($http)['createTransactionRequest']['transactionRequest'];

    expect($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('40000000002')
        ->and($tr['transactionType'])->toBe('priorAuthCaptureTransaction')
        ->and($tr['amount'])->toBe('50.00')
        ->and($tr['refTransId'])->toBe('40000000001');
});

it('refunds a settled transaction by reference and amount', function (): void {
    $http = (new FakeHttpClient)->queueJson(authorizeNetApproved('40000000003'));

    $result = authorizeNetGateway($http)->refund(new RefundRequest(
        transactionId: '40000000001',
        money: Money::minor(2500, 'USD'),
    ));

    $tr = authorizeNetBody($http)['createTransactionRequest']['transactionRequest'];

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->refundId)->toBe('40000000003')
        ->and($tr['transactionType'])->toBe('refundTransaction')
        ->and($tr['amount'])->toBe('25.00')
        ->and($tr['refTransId'])->toBe('40000000001');
});

it('voids an unsettled transaction without an amount', function (): void {
    $http = (new FakeHttpClient)->queueJson(authorizeNetApproved('40000000004'));

    $result = authorizeNetGateway($http)->void(new VoidRequest(transactionId: '40000000001'));

    $tr = authorizeNetBody($http)['createTransactionRequest']['transactionRequest'];

    expect($result->status)->toBe(PaymentStatus::Voided)
        ->and($tr)->toBe([
            'transactionType' => 'voidTransaction',
            'refTransId' => '40000000001',
        ]);
});

it('looks up a transaction and maps its settlement status, unwrapping the response envelope', function (): void {
    $http = (new FakeHttpClient)->queueJson([
        'getTransactionDetailsResponse' => [
            'messages' => ['resultCode' => 'Ok'],
            'transaction' => [
                'transId' => '40000000001',
                'transactionStatus' => 'settledSuccessfully',
                'authAmount' => '50.00',
                'settleAmount' => '50.00',
                'order' => ['invoiceNumber' => 'ORD1'],
                'payment' => ['creditCard' => ['cardNumber' => 'XXXX1111']],
            ],
        ],
    ]);

    $snapshot = authorizeNetGateway($http)->getTransaction('40000000001');

    expect($snapshot->transactionId)->toBe('40000000001')
        ->and($snapshot->status)->toBe(PaymentStatus::Captured)
        ->and($snapshot->orderReference)->toBe('ORD1')
        ->and($snapshot->money?->toDecimalString())->toBe('50.00')
        ->and($snapshot->money?->currency)->toBe('USD')
        ->and(authorizeNetBody($http)['getTransactionDetailsRequest']['transId'])->toBe('40000000001');
});

it('verifies a genuine Authorize.Net webhook signature', function (): void {
    $body = (string) json_encode([
        'notificationId' => 'n1',
        'eventType' => 'net.authorize.payment.authcapture.created',
        'payload' => ['responseCode' => 1, 'id' => '40000000001'],
    ]);
    $signature = 'sha512='.strtoupper(hash_hmac('sha512', $body, 'SIGNATURE_KEY'));

    $event = authorizeNetGateway(new FakeHttpClient)->verifyWebhook($body, ['X-ANET-Signature' => $signature]);

    expect($event->verified)->toBeTrue()
        ->and($event->eventType)->toBe('net.authorize.payment.authcapture.created')
        ->and($event->transactionId)->toBe('40000000001')
        ->and($event->status)->toBe(PaymentStatus::Captured);
});

it('rejects a webhook with a tampered signature', function (): void {
    $body = (string) json_encode(['eventType' => 'net.authorize.payment.void.created', 'payload' => ['id' => '40000000001']]);

    $event = authorizeNetGateway(new FakeHttpClient)->verifyWebhook($body, ['X-ANET-Signature' => 'sha512=DEADBEEF']);

    expect($event->verified)->toBeFalse()
        ->and($event->status)->toBe(PaymentStatus::Voided);
});

it('is resolvable through the factory', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(authorizeNetCredentials()));

    expect($factory->make(GatewayName::AuthorizeNet, authorizeNetCredentials()))->toBeInstanceOf(AuthorizeNetGateway::class);
});
