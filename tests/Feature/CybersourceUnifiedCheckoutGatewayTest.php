<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Exception\UnsupportedOperationException;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function gatewayWithFakeHttp(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function recordedBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('creates a checkout session and returns the capture-context JWT', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $jwt = fakeJwt(['ctx' => [['data' => ['clientLibrary' => 'https://lib.test/accept.js', 'clientLibraryIntegrity' => 'sha256-x']]]]);
    $http->queueBody($jwt);

    $session = $gateway->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        targetOrigins: ['https://shop.test'],
    ));

    $request = $http->lastRequest();

    expect($session->jwt)->toBe($jwt)
        ->and($session->clientLibrary)->toBe('https://lib.test/accept.js')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/up/v1/capture-contexts')
        ->and($request?->method)->toBe('POST')
        ->and($request?->header('Signature'))->not->toBeNull()
        ->and(recordedBody($http)['clientVersion'])->toBe('0.34');
});

it('charges a transient token and maps an authorized response', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'txn_1', 'status' => 'AUTHORIZED']);

    $result = $gateway->charge(new ChargeRequest(transientToken: 'tok', money: Money::minor(2599, 'USD')));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Authorized)
        ->and($result->transactionId)->toBe('txn_1')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/payments')
        ->and(recordedBody($http)['tokenInformation']['transientTokenJwt'])->toBe('tok');
});

it('captures an authorization against the transaction id', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'txn_1', 'status' => 'CAPTURED']);

    $result = $gateway->capture(new CaptureRequest(transactionId: 'txn_1', money: Money::minor(2599, 'USD')));

    expect($result->status)->toBe(PaymentStatus::Captured)
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/payments/txn_1/captures');
});

it('refunds a captured payment', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'ref_1', 'status' => 'PENDING']);

    $result = $gateway->refund(new RefundRequest(transactionId: 'txn_1', money: Money::minor(1000, 'USD'), reason: 'duplicate'));

    expect($result->success)->toBeTrue()
        ->and($result->refundId)->toBe('ref_1')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/payments/txn_1/refunds')
        ->and(recordedBody($http)['clientReferenceInformation']['comments'])->toBe('duplicate');
});

it('voids a payment', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'txn_1', 'status' => 'VOIDED']);

    $result = $gateway->void(new VoidRequest(transactionId: 'txn_1'));

    expect($result->status)->toBe(PaymentStatus::Voided)
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/payments/txn_1/voids');
});

it('reverses an authorization', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'txn_1', 'status' => 'REVERSED']);

    $result = $gateway->reverseAuthorization(new ReversalRequest(transactionId: 'txn_1', money: Money::minor(2599, 'USD')));

    expect($result->status)->toBe(PaymentStatus::Reversed)
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/payments/txn_1/reversals');
});

it('enrolls payer auth and surfaces the step-up challenge', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson([
        'status' => 'PENDING_AUTHENTICATION',
        'consumerAuthenticationInformation' => [
            'stepUpUrl' => 'https://acs.test/step-up',
            'accessToken' => 'jwt',
            'authenticationTransactionId' => 'auth_1',
        ],
    ]);

    $result = $gateway->enrollPayerAuth(new PayerAuthEnrollRequest(transientToken: 'tok', money: Money::minor(2599, 'USD')));

    expect($result->success)->toBeTrue()
        ->and($result->requiresChallenge())->toBeTrue()
        ->and($result->stepUpUrl)->toBe('https://acs.test/step-up')
        ->and($result->authenticationTransactionId)->toBe('auth_1')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/risk/v1/authentications');
});

it('validates payer auth results', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson([
        'status' => 'AUTHENTICATION_SUCCESSFUL',
        'consumerAuthenticationInformation' => ['cavv' => 'abc', 'eci' => '05'],
    ]);

    $result = $gateway->validatePayerAuth(new ValidatePayerAuthRequest(authenticationTransactionId: 'auth_1', money: Money::minor(2599, 'USD')));

    expect($result->success)->toBeTrue()
        ->and($result->consumerAuthenticationInformation['cavv'])->toBe('abc')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/risk/v1/authentication-results');
});

it('vaults a card through the three TMS calls', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'ii_1'])
        ->queueJson(['id' => 'cust_1'])
        ->queueJson(['id' => 'pi_1']);

    $result = $gateway->vaultInstrument(new TokenizeInstrumentRequest(
        cardNumber: '4111111111111111',
        expirationMonth: '12',
        expirationYear: '2030',
        cardType: '001',
    ));

    expect($result->success)->toBeTrue()
        ->and($result->instrumentIdentifierId)->toBe('ii_1')
        ->and($result->customerId)->toBe('cust_1')
        ->and($result->paymentInstrumentId)->toBe('pi_1')
        ->and($http->requestCount())->toBe(3)
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/tms/v2/customers/cust_1/payment-instruments');
});

it('charges a stored credential as a merchant-initiated transaction', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'mit_1', 'status' => 'AUTHORIZED']);

    $result = $gateway->chargeStoredCredential(new StoredCredentialChargeRequest(
        paymentInstrumentId: 'pi_1',
        money: Money::minor(1500, 'USD'),
    ));

    expect($result->success)->toBeTrue()
        ->and($result->transactionId)->toBe('mit_1')
        ->and(recordedBody($http)['paymentInformation']['paymentInstrument']['id'])->toBe('pi_1');
});

it('fetches a transaction via a GET request without a digest header', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'txn_1', 'status' => 'AUTHORIZED', 'clientReferenceInformation' => ['code' => 'ORD-1']]);

    $snapshot = $gateway->getTransaction('txn_1');
    $request = $http->lastRequest();

    expect($snapshot->transactionId)->toBe('txn_1')
        ->and($snapshot->status)->toBe(PaymentStatus::Authorized)
        ->and($snapshot->orderReference)->toBe('ORD-1')
        ->and($request?->method)->toBe('GET')
        ->and($request?->header('Digest'))->toBeNull();
});

it('returns the first transaction summary from a search', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['_embedded' => ['transactionSummaries' => [['id' => 't1', 'status' => 'CAPTURED']]]]);

    $snapshot = $gateway->searchTransaction('clientReferenceInformation.code:ORD-1');

    expect($snapshot?->transactionId)->toBe('t1')
        ->and($snapshot?->status)->toBe(PaymentStatus::Captured);
});

it('returns null when a search finds no transactions', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['_embedded' => ['transactionSummaries' => []]]);

    expect($gateway->searchTransaction('clientReferenceInformation.code:none'))->toBeNull();
});

it('throws a GatewayRequestException on a non-2xx response', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['reason' => 'INVALID_DATA', 'message' => 'bad request'], 400);

    expect(fn (): PaymentResult => $gateway->charge(new ChargeRequest(transientToken: 'tok', money: Money::minor(100, 'USD'))))
        ->toThrow(GatewayRequestException::class);
});

it('throws UnsupportedOperationException for an operation a driver does not implement', function (): void {
    $gateway = new class(testCredentials()) extends AbstractPaymentGateway
    {
        public function name(): GatewayName
        {
            return GatewayName::CybersourceUnifiedCheckout;
        }
    };

    expect(fn (): PaymentResult => $gateway->charge(new ChargeRequest(transientToken: 'tok', money: Money::minor(100, 'USD'))))
        ->toThrow(UnsupportedOperationException::class);
});
