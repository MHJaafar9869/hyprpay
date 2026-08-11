<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\Enum\MandateCompletionType;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\CaptureContextPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\CapturePayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PayerAuthEnrollPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PaymentPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\StoredCredentialPayload;

it('builds a capture-context payload with amount, mandate and optional billTo', function (): void {
    $request = new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        targetOrigins: ['https://shop.test'],
        allowedCardNetworks: ['VISA', 'MASTERCARD'],
        allowedPaymentTypes: ['PANENTRY', 'APPLEPAY'],
        billTo: new BillingAddress(firstName: 'Ada', country: 'EG'),
    );

    $payload = CaptureContextPayload::build($request, testCredentials());

    expect($payload['orderInformation']['amountDetails'])->toBe(['totalAmount' => '100.00', 'currency' => 'EGP'])
        ->and($payload['targetOrigins'])->toBe(['https://shop.test'])
        ->and($payload['allowedPaymentTypes'])->toBe(['PANENTRY', 'APPLEPAY'])
        ->and($payload['captureMandate']['billingType'])->toBe('FULL')
        ->and($payload['orderInformation']['billTo'])->toBe(['firstName' => 'Ada', 'country' => 'EG'])
        ->and($payload['country'])->toBe('EG');
});

it('runs Decision Manager by default in the orchestrated completeMandate block', function (): void {
    $payload = CaptureContextPayload::build(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        targetOrigins: ['https://shop.test'],
        completeMandate: MandateCompletionType::Capture,
    ), testCredentials());

    expect($payload['completeMandate'])->toBe(['type' => 'CAPTURE', 'decisionManager' => true]);
});

it('lets the orchestrated flow opt out of Decision Manager', function (): void {
    $payload = CaptureContextPayload::build(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        targetOrigins: ['https://shop.test'],
        completeMandate: MandateCompletionType::Capture,
        decisionManager: false,
    ), testCredentials());

    expect($payload['completeMandate'])->toBe(['type' => 'CAPTURE', 'decisionManager' => false]);
});

it('omits completeMandate for the manual transient-token flow', function (): void {
    $payload = CaptureContextPayload::build(new CheckoutSessionRequest(
        money: Money::minor(10000, 'EGP'),
        targetOrigins: ['https://shop.test'],
    ), testCredentials());

    expect($payload)->not->toHaveKey('completeMandate');
});

it('builds a payment payload carrying the transient token, amount and capture flag', function (): void {
    $request = new ChargeRequest(
        transientToken: 'tok_123',
        money: Money::minor(2599, 'USD'),
        capture: false,
        orderReference: 'ORDER-9',
        consumerAuthentication: ['cavv' => 'abc', 'eci' => '05'],
        deviceFingerprintId: 'fp_1',
        useRawFingerprintSessionId: true,
    );

    $payload = PaymentPayload::build($request);

    expect($payload['tokenInformation']['transientTokenJwt'])->toBe('tok_123')
        ->and($payload['processingInformation']['capture'])->toBeFalse()
        ->and($payload['orderInformation']['amountDetails'])->toBe(['totalAmount' => '25.99', 'currency' => 'USD'])
        ->and($payload['clientReferenceInformation']['code'])->toBe('ORDER-9')
        ->and($payload['consumerAuthenticationInformation'])->toBe(['cavv' => 'abc', 'eci' => '05'])
        ->and($payload['deviceInformation'])->toBe(['fingerprintSessionId' => 'fp_1', 'useRawFingerprintSessionId' => true]);
});

it('emits the fingerprint session id without the raw flag by default', function (): void {
    $payload = PaymentPayload::build(new ChargeRequest(
        transientToken: 'tok',
        money: Money::minor(100, 'USD'),
        deviceFingerprintId: 'fp_default',
    ));

    expect($payload['deviceInformation'])->toBe(['fingerprintSessionId' => 'fp_default']);
});

it('omits optional payment sections when not provided', function (): void {
    $payload = PaymentPayload::build(new ChargeRequest(transientToken: 'tok', money: Money::minor(100, 'USD')));

    expect($payload)->not->toHaveKey('clientReferenceInformation')
        ->and($payload)->not->toHaveKey('consumerAuthenticationInformation')
        ->and($payload)->not->toHaveKey('deviceInformation')
        ->and($payload['orderInformation'])->not->toHaveKey('billTo');
});

it('falls back to the transaction id for the capture client reference', function (): void {
    $payload = CapturePayload::build(new CaptureRequest(transactionId: 'txn_1', money: Money::minor(500, 'USD')));

    expect($payload['clientReferenceInformation']['code'])->toBe('txn_1')
        ->and($payload['orderInformation']['amountDetails']['totalAmount'])->toBe('5.00');
});

it('marks a stored-credential charge as merchant-initiated with stored credential used', function (): void {
    $payload = StoredCredentialPayload::build(new StoredCredentialChargeRequest(
        paymentInstrumentId: 'pi_1',
        money: Money::minor(1500, 'USD'),
        initiator: CredentialInitiator::Merchant,
        isFirstCharge: false,
        customerId: 'cust_1',
    ));

    $initiator = $payload['processingInformation']['authorizationOptions']['initiator'];

    expect($initiator['type'])->toBe('merchant')
        ->and($initiator['storedCredentialUsed'])->toBeTrue()
        ->and($initiator)->toHaveKey('merchantInitiatedTransaction')
        ->and($payload['paymentInformation']['paymentInstrument']['id'])->toBe('pi_1')
        ->and($payload['paymentInformation']['customer']['id'])->toBe('cust_1')
        ->and($payload)->not->toHaveKey('deviceInformation');
});

it('attaches the device fingerprint to a stored-credential charge', function (): void {
    $payload = StoredCredentialPayload::build(new StoredCredentialChargeRequest(
        paymentInstrumentId: 'pi_1',
        money: Money::minor(1500, 'USD'),
        deviceFingerprintId: 'fp_2',
    ));

    expect($payload['deviceInformation'])->toBe(['fingerprintSessionId' => 'fp_2']);
});

it('attaches the device fingerprint to a payer-auth enrollment', function (): void {
    $payload = PayerAuthEnrollPayload::build(new PayerAuthEnrollRequest(
        transientToken: 'tok',
        money: Money::minor(2000, 'USD'),
        deviceFingerprintId: 'fp_3',
        useRawFingerprintSessionId: true,
    ));

    expect($payload['deviceInformation'])->toBe(['fingerprintSessionId' => 'fp_3', 'useRawFingerprintSessionId' => true]);
});
