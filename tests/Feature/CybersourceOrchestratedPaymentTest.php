<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\ConfirmOrchestratedPaymentRequest;
use Hyprpay\Payments\Domain\Enum\MandateCompletionType;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Exception\PaymentVerificationException;
use Hyprpay\Payments\Domain\Result\OrchestratedPaymentResult;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * Build a CyberSource gateway backed by a fake HTTP client for orchestrated-flow tests.
 */
function orchestratedGateway(?FakeHttpClient $http = null): CybersourceUnifiedCheckoutGateway
{
    return new CybersourceUnifiedCheckoutGateway(testCredentials(), $http ?? new FakeHttpClient);
}

/**
 * Build a completed-payment result JWT claim set shaped like CyberSource's orchestrated result.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orchestratedClaims(string $status = 'CAPTURED', array $overrides = []): array
{
    $base = [
        'id' => 'txn_123',
        'status' => $status,
        'iss' => 'Flex/08',
        'iat' => time() - 10,
        'exp' => time() + 3600,
        'details' => [
            'clientReferenceInformation' => ['code' => 'ORD-1'],
            'orderInformation' => ['amountDetails' => ['totalAmount' => '100.00', 'currency' => 'USD']],
            'tokenInformation' => [
                'instrumentIdentifier' => ['id' => 'ii_abc'],
                'paymentInstrument' => ['id' => 'pi_abc'],
                'customer' => ['id' => 'cust_abc'],
            ],
            'paymentInformation' => [
                'tokenizedCard' => ['type' => '001', 'suffix' => '1111', 'expirationMonth' => '12', 'expirationYear' => '2030'],
                'paymentType' => ['type' => 'CARD'],
            ],
            'processorInformation' => ['networkTransactionId' => 'ntx_999', 'transactionId' => 'ptx_888'],
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

it('verifies a captured card result and extracts the reusable TMS token', function (): void {
    $http = new FakeHttpClient;

    $result = orchestratedGateway($http)->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
        resultJwt: signedResultJwt(orchestratedClaims('CAPTURED')),
        captureContextJwt: captureContextWithJwk(),
        expectedMoney: Money::minor(10000, 'USD'),
        orderReference: 'ORD-1',
    ));

    expect($result)->toBeInstanceOf(OrchestratedPaymentResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Captured)
        ->and($result->transactionId)->toBe('txn_123')
        ->and($result->orderReference)->toBe('ORD-1')
        ->and($result->isWallet)->toBeFalse()
        ->and($result->instrumentIdentifierId)->toBe('ii_abc')
        ->and($result->paymentInstrumentId)->toBe('pi_abc')
        ->and($result->customerId)->toBe('cust_abc')
        ->and($result->networkTransactionId)->toBe('ntx_999')
        ->and($result->cardBrand)->toBe('visa')
        ->and($result->cardLast4)->toBe('1111')
        ->and($result->cardExpiryMonth)->toBe('12')
        ->and($result->cardExpiryYear)->toBe('2030')
        ->and($http->requestCount())->toBe(0);
});

it('returns an unsuccessful result for an authentic declined payment without throwing', function (): void {
    $result = orchestratedGateway()->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
        resultJwt: signedResultJwt(orchestratedClaims('DECLINED')),
        captureContextJwt: captureContextWithJwk(),
        expectedMoney: Money::minor(10000, 'USD'),
    ));

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(PaymentStatus::Declined)
        ->and($result->transactionId)->toBe('txn_123');
});

it('rejects a result JWT with a tampered signature', function (): void {
    $jwt = signedResultJwt(orchestratedClaims('CAPTURED'));
    $parts = explode('.', $jwt);
    $parts[2] = rtrim(strtr(base64_encode('tampered-signature'), '+/', '-_'), '=');
    $tampered = implode('.', $parts);

    expect(fn (): OrchestratedPaymentResult => orchestratedGateway()->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
        resultJwt: $tampered,
        captureContextJwt: captureContextWithJwk(),
        expectedMoney: Money::minor(10000, 'USD'),
    )))->toThrow(PaymentVerificationException::class);
});

it('rejects a result JWT signed by the wrong key', function (): void {
    $wrong = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($wrong, $wrongPem);

    expect(fn (): OrchestratedPaymentResult => orchestratedGateway()->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
        resultJwt: signedResultJwt(orchestratedClaims('CAPTURED'), 'test-kid', $wrongPem),
        captureContextJwt: captureContextWithJwk(),
        expectedMoney: Money::minor(10000, 'USD'),
    )))->toThrow(PaymentVerificationException::class);
});

it('rejects a result JWT whose amount does not match the request', function (): void {
    expect(fn (): OrchestratedPaymentResult => orchestratedGateway()->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
        resultJwt: signedResultJwt(orchestratedClaims('CAPTURED')),
        captureContextJwt: captureContextWithJwk(),
        expectedMoney: Money::minor(20000, 'USD'),
    )))->toThrow(PaymentVerificationException::class);
});

it('rejects a result JWT whose currency does not match the request', function (): void {
    expect(fn (): OrchestratedPaymentResult => orchestratedGateway()->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
        resultJwt: signedResultJwt(orchestratedClaims('CAPTURED')),
        captureContextJwt: captureContextWithJwk(),
        expectedMoney: Money::minor(10000, 'EUR'),
    )))->toThrow(PaymentVerificationException::class);
});

it('flags a Google Pay wallet result and withholds the reusable token', function (): void {
    $claims = orchestratedClaims('AUTHORIZED', [
        'details' => ['paymentInformation' => ['paymentType' => ['type' => 'GOOGLEPAY']]],
    ]);

    $result = orchestratedGateway()->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
        resultJwt: signedResultJwt($claims),
        captureContextJwt: captureContextWithJwk(),
        expectedMoney: Money::minor(10000, 'USD'),
    ));

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Authorized)
        ->and($result->isWallet)->toBeTrue()
        ->and($result->instrumentIdentifierId)->toBeNull()
        ->and($result->paymentInstrumentId)->toBeNull()
        ->and($result->customerId)->toBeNull();
});

it('rejects an expired result JWT', function (): void {
    $claims = orchestratedClaims('CAPTURED', ['iat' => time() - 7200, 'exp' => time() - 3600]);

    expect(fn (): OrchestratedPaymentResult => orchestratedGateway()->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
        resultJwt: signedResultJwt($claims),
        captureContextJwt: captureContextWithJwk(),
        expectedMoney: Money::minor(10000, 'USD'),
    )))->toThrow(PaymentVerificationException::class);
});

it('rejects a result JWT when the capture context carries no verification key', function (): void {
    expect(fn (): OrchestratedPaymentResult => orchestratedGateway()->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
        resultJwt: signedResultJwt(orchestratedClaims('CAPTURED')),
        captureContextJwt: fakeJwt(['ctx' => [['data' => ['clientLibrary' => 'https://lib.test/x.js']]]]),
        expectedMoney: Money::minor(10000, 'USD'),
    )))->toThrow(PaymentVerificationException::class);
});

it('adds a completeMandate block to the capture context when orchestration is requested', function (): void {
    $http = new FakeHttpClient;
    $http->queueBody(fakeJwt(['ctx' => [['data' => ['clientLibrary' => 'https://lib.test/accept.js', 'clientLibraryIntegrity' => 'sha256-x']]]]));

    orchestratedGateway($http)->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'USD'),
        targetOrigins: ['https://shop.test'],
        completeMandate: MandateCompletionType::Capture,
    ));

    $body = json_decode((string) $http->lastRequest()?->body, true);

    expect($body['completeMandate'])->toBe(['type' => 'CAPTURE', 'decisionManager' => true]);
});

it('adds a tms token-creation block to the mandate when token creation is requested', function (): void {
    $http = new FakeHttpClient;
    $http->queueBody(fakeJwt(['ctx' => [['data' => ['clientLibrary' => 'https://lib.test/accept.js', 'clientLibraryIntegrity' => 'sha256-x']]]]));

    orchestratedGateway($http)->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'USD'),
        targetOrigins: ['https://shop.test'],
        completeMandate: MandateCompletionType::Capture,
        createToken: true,
    ));

    $body = json_decode((string) $http->lastRequest()?->body, true);

    expect($body['completeMandate'])->toBe([
        'type' => 'CAPTURE',
        'decisionManager' => true,
        'tms' => [
            'tokenCreate' => true,
            'tokenTypes' => ['customer', 'paymentInstrument', 'instrumentIdentifier'],
        ],
    ]);
});

it('omits the completeMandate block for the manual transient-token flow', function (): void {
    $http = new FakeHttpClient;
    $http->queueBody(fakeJwt(['ctx' => [['data' => ['clientLibrary' => 'https://lib.test/accept.js', 'clientLibraryIntegrity' => 'sha256-x']]]]));

    orchestratedGateway($http)->createCheckoutSession(new CheckoutSessionRequest(
        money: Money::minor(10000, 'USD'),
        targetOrigins: ['https://shop.test'],
    ));

    $body = json_decode((string) $http->lastRequest()?->body, true);

    expect($body)->not->toHaveKey('completeMandate');
});
