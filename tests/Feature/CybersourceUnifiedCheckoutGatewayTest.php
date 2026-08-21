<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\DccRateRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Exception\UnsupportedOperationException;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\ValueObject\DecryptedWalletToken;
use Hyprpay\Payments\Domain\ValueObject\EncryptedWalletToken;
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

it('charges an apple pay wallet token as tokenizedCard (canonical decrypted path)', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'ap_1', 'status' => 'AUTHORIZED']);

    $result = $gateway->chargeWallet(new WalletChargeRequest(
        token: new DecryptedWalletToken(
            number: '4111111111111111',
            cryptogram: 'AceY+igABPs3jdwNaDg3MAACAAA=',
            expiryMonth: '12',
            expiryYear: '2031',
            eci: '05',
            cardType: '001',
        ),
        wallet: WalletType::ApplePay,
        money: Money::minor(2599, 'USD'),
    ));

    $body = recordedBody($http);
    $tokenizedCard = $body['paymentInformation']['tokenizedCard'];

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Authorized)
        ->and($result->transactionId)->toBe('ap_1')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/payments')
        ->and($body['processingInformation']['paymentSolution'])->toBe('001')
        ->and($tokenizedCard['number'])->toBe('4111111111111111')
        ->and($tokenizedCard['cryptogram'])->toBe('AceY+igABPs3jdwNaDg3MAACAAA=')
        ->and($tokenizedCard['expirationMonth'])->toBe('12')
        ->and($tokenizedCard['expirationYear'])->toBe('2031')
        ->and($tokenizedCard['transactionType'])->toBe('1')
        ->and($tokenizedCard['type'])->toBe('001')
        ->and($body['consumerAuthenticationInformation']['eciRaw'])->toBe('05');
});

it('omits the card type and ECI from a tokenizedCard wallet charge when not supplied', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'ap_2', 'status' => 'AUTHORIZED']);

    $gateway->chargeWallet(new WalletChargeRequest(
        token: new DecryptedWalletToken(number: '4111111111111111', cryptogram: 'abc', expiryMonth: '01', expiryYear: '2030'),
        wallet: WalletType::ApplePay,
        money: Money::minor(100, 'USD'),
    ));

    $body = recordedBody($http);

    expect($body['paymentInformation']['tokenizedCard'])->not->toHaveKey('type')
        ->and($body)->not->toHaveKey('consumerAuthenticationInformation');
});

it('forwards an encrypted wallet token as fluidData for CyberSource to decrypt', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'ap_3', 'status' => 'AUTHORIZED']);

    $gateway->chargeWallet(new WalletChargeRequest(
        token: new EncryptedWalletToken('{"data":"encrypted-blob"}'),
        wallet: WalletType::ApplePay,
        money: Money::minor(2599, 'USD'),
    ));

    $fluidData = recordedBody($http)['paymentInformation']['fluidData'];

    expect(recordedBody($http)['processingInformation']['paymentSolution'])->toBe('001')
        ->and($fluidData['descriptor'])->toBe(base64_encode('FID=COMMON.APPLE.INAPP.PAYMENT'))
        ->and($fluidData['encoding'])->toBe('Base64')
        ->and($fluidData['value'])->toBe(base64_encode('{"data":"encrypted-blob"}'));
});

it('maps a google pay wallet token to payment solution 012', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'gp_1', 'status' => 'AUTHORIZED']);

    $gateway->chargeWallet(new WalletChargeRequest(
        token: new EncryptedWalletToken('google-token'),
        wallet: WalletType::GooglePay,
        money: Money::minor(500, 'USD'),
    ));

    expect(recordedBody($http)['processingInformation']['paymentSolution'])->toBe('012')
        ->and(recordedBody($http)['paymentInformation']['fluidData']['descriptor'])->toBe(base64_encode('FID=COMMON.GOOGLE.INAPP.PAYMENT'));
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

it('reconciles a reference to the first settled transaction, searching by client reference code', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['_embedded' => ['transactionSummaries' => [
        ['id' => 'pending1', 'applicationInformation' => ['status' => 'PENDING']],
        ['id' => 'auth1', 'applicationInformation' => ['status' => 'AUTHORIZED']],
    ]]]);

    $snapshot = $gateway->findSuccessfulTransactionByReference('INST-9');
    $request = $http->lastRequest();

    expect($snapshot?->transactionId)->toBe('auth1')
        ->and($snapshot?->status)->toBe(PaymentStatus::Authorized)
        ->and($request?->method)->toBe('POST')
        ->and(json_decode((string) $request?->body, true)['query'])->toContain('INST-9');
});

it('does not adopt a partial authorization when reconciling', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['_embedded' => ['transactionSummaries' => [
        ['id' => 'partial1', 'applicationInformation' => ['status' => 'PARTIAL_AUTHORIZED']],
        ['id' => 'cap1', 'applicationInformation' => ['status' => 'CAPTURED']],
    ]]]);

    $snapshot = $gateway->findSuccessfulTransactionByReference('INST-9');

    expect($snapshot?->transactionId)->toBe('cap1')
        ->and($snapshot?->status)->toBe(PaymentStatus::Captured);
});

it('returns null when reconciling finds only unsettled transactions', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['_embedded' => ['transactionSummaries' => [
        ['id' => 'd1', 'applicationInformation' => ['status' => 'DECLINED']],
    ]]]);

    expect($gateway->findSuccessfulTransactionByReference('INST-9'))->toBeNull();
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

it('requests a DCC rate and maps the quote', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson([
        'currencyConversion' => ['id' => 'RATE_1', 'exchangeRate' => '48.00', 'exchangeRateTimeStamp' => '20250807 100000'],
        'orderInformation' => ['amountDetails' => ['originalAmount' => '480.00', 'originalCurrency' => 'EGP', 'totalAmount' => '10.00', 'currency' => 'USD']],
    ]);

    $quote = $gateway->requestDccRate(new DccRateRequest(
        money: Money::minor(48000, 'EGP'),
        cardNumber: '4111111111111111',
        orderReference: 'ORD1',
    ));

    $body = recordedBody($http);

    expect($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/vas/v1/currencyconversion')
        ->and($body['orderInformation']['amountDetails']['originalAmount'])->toBe('480.00')
        ->and($body['orderInformation']['amountDetails']['originalCurrency'])->toBe('EGP')
        ->and($body['paymentInformation']['card']['number'])->toBe('4111111111111111')
        ->and($quote->offered)->toBeTrue()
        ->and($quote->id)->toBe('RATE_1')
        ->and($quote->exchangeRate)->toBe('48.00')
        ->and($quote->exchangeRateTimeStamp)->toBe('20250807 100000')
        ->and($quote->convertedAmount?->toDecimalString())->toBe('10.00')
        ->and($quote->convertedAmount?->currency)->toBe('USD')
        ->and($quote->originalAmount?->toDecimalString())->toBe('480.00');
});

it('returns a not-offered quote when no rate id is present', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['orderInformation' => ['amountDetails' => []]]);

    $quote = $gateway->requestDccRate(new DccRateRequest(money: Money::minor(48000, 'EGP'), cardNumber: '4111111111111111'));

    expect($quote->offered)->toBeFalse()
        ->and($quote->id)->toBeNull()
        ->and($quote->convertedAmount)->toBeNull();
});

it('threads a DCC quote into the authorization amount details', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'txn_1', 'status' => 'AUTHORIZED']);

    $converted = Money::minor(1000, 'USD');
    $quote = new DccQuote(
        offered: true,
        id: 'RATE_1',
        exchangeRate: '48.00',
        originalAmount: Money::minor(48000, 'EGP'),
        convertedAmount: $converted,
        exchangeRateTimeStamp: '20250807 100000',
    );

    $gateway->charge(new ChargeRequest(transientToken: 'tok', money: $converted, dcc: $quote));

    $amount = recordedBody($http)['orderInformation']['amountDetails'];

    expect($amount['totalAmount'])->toBe('10.00')
        ->and($amount['currency'])->toBe('USD')
        ->and($amount['originalAmount'])->toBe('480.00')
        ->and($amount['originalCurrency'])->toBe('EGP')
        ->and($amount['exchangeRate'])->toBe('48.00')
        ->and($amount['exchangeRateTimeStamp'])->toBe('20250807 100000')
        ->and($amount['currencyConversion']['indicator'])->toBe('1')
        ->and($amount['currencyConversion']['id'])->toBe('RATE_1')
        ->and($amount['currencyConversion']['reconciliationId'])->toBe('RATE_1');
});

it('captures and refunds at the same DCC rate', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $converted = Money::minor(1000, 'USD');
    $quote = new DccQuote(offered: true, id: 'RATE_1', exchangeRate: '48.00', originalAmount: Money::minor(48000, 'EGP'), convertedAmount: $converted);

    $http->queueJson(['id' => 'txn_1', 'status' => 'CAPTURED']);
    $gateway->capture(new CaptureRequest(transactionId: 'txn_1', money: $converted, dcc: $quote));
    $capture = recordedBody($http)['orderInformation']['amountDetails'];

    $http->queueJson(['id' => 'ref_1', 'status' => 'PENDING']);
    $gateway->refund(new RefundRequest(transactionId: 'txn_1', money: $converted, dcc: $quote));
    $refund = recordedBody($http)['orderInformation']['amountDetails'];

    expect($capture['currency'])->toBe('USD')
        ->and($capture['originalCurrency'])->toBe('EGP')
        ->and($capture['currencyConversion']['id'])->toBe('RATE_1')
        ->and($refund['currency'])->toBe('USD')
        ->and($refund['currencyConversion']['reconciliationId'])->toBe('RATE_1');
});

it('marks a DCC reversal amount in the billing currency', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'txn_1', 'status' => 'REVERSED']);

    $converted = Money::minor(1000, 'USD');
    $quote = new DccQuote(offered: true, id: 'RATE_1', exchangeRate: '48.00', originalAmount: Money::minor(48000, 'EGP'), convertedAmount: $converted);

    $gateway->reverseAuthorization(new ReversalRequest(transactionId: 'txn_1', money: $converted, dcc: $quote));

    $amount = recordedBody($http)['reversalInformation']['amountDetails'];

    expect($amount['totalAmount'])->toBe('10.00')->and($amount['currency'])->toBe('USD');
});

it('omits currency conversion for a standard (non-DCC) charge', function (): void {
    [$gateway, $http] = gatewayWithFakeHttp();
    $http->queueJson(['id' => 'txn_1', 'status' => 'AUTHORIZED']);

    $gateway->charge(new ChargeRequest(transientToken: 'tok', money: Money::minor(2599, 'USD')));

    expect(recordedBody($http)['orderInformation']['amountDetails'])->not->toHaveKey('currencyConversion');
});
