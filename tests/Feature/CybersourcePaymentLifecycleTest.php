<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CreditRequest;
use Hyprpay\Payments\Domain\Command\IncrementAuthorizationRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\TimeoutVoidRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function lifecycleGateway(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function lifecycleBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('raises an existing hold rather than placing a second one', function (): void {
    [$gateway, $http] = lifecycleGateway();
    $http->queueJson(['id' => 'pay_1', 'status' => 'AUTHORIZED']);

    $result = $gateway->incrementAuthorization(new IncrementAuthorizationRequest(
        transactionId: 'pay_1',
        additionalAmount: Money::minor(5000, 'USD'),
        reason: '5',
        orderReference: 'ORDER-1',
    ));

    $request = $http->lastRequest();
    $body = lifecycleBody($http);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Authorized)
        ->and($request?->method)->toBe('PATCH')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/pts/v2/payments/pay_1')
        ->and($request?->header('v-c-idempotency-id'))->toBe('ORDER-1')
        ->and($body['orderInformation']['amountDetails'])->toBe([
            'additionalAmount' => '50.00',   // the amount to ADD, not the new total
            'currency' => 'USD',
        ])
        ->and($body['clientReferenceInformation']['code'])->toBe('ORDER-1');
});

it('credits a card with no original transaction behind it', function (): void {
    [$gateway, $http] = lifecycleGateway();
    $http->queueJson(['id' => 'cr_1', 'status' => 'PENDING']);

    $result = $gateway->creditPayment(new CreditRequest(
        money: Money::minor(2500, 'USD'),
        transientToken: 'header.payload.sig',
        orderReference: 'GOODWILL-1',
        billTo: new BillingAddress(firstName: 'Jane', lastName: 'Doe', country: 'US'),
        merchantTransactionId: 'mtid-credit-1',
    ));

    $body = lifecycleBody($http);

    expect($result->success)->toBeTrue()
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/credits')
        ->and($body['orderInformation']['amountDetails'])->toBe(['totalAmount' => '25.00', 'currency' => 'USD'])
        ->and($body['orderInformation']['billTo']['country'])->toBe('US')
        ->and($body['tokenInformation'])->toBe(['transientTokenJwt' => 'header.payload.sig'])
        ->and($body['clientReferenceInformation'])->toBe([
            'code' => 'GOODWILL-1',
            'transactionId' => 'mtid-credit-1',
        ]);
});

it('falls back to Refunded when a credit response carries no recognised status', function (): void {
    [$gateway] = lifecycleGateway();

    $result = $gateway->creditPayment(new CreditRequest(money: Money::minor(100, 'USD'), paymentInstrumentId: 'pi_1'));

    expect($result->status)->toBe(PaymentStatus::Refunded)
        ->and($result->success)->toBeTrue();
});

it('credits a vaulted instrument instead of a token', function (): void {
    [$gateway, $http] = lifecycleGateway();

    $gateway->creditPayment(new CreditRequest(
        money: Money::minor(100, 'USD'), paymentInstrumentId: 'pi_1', customerId: 'cust_1',
    ));

    $body = lifecycleBody($http);

    expect($body['paymentInformation'])->toBe([
        'paymentInstrument' => ['id' => 'pi_1'],
        'customer' => ['id' => 'cust_1'],
    ])
        ->and($body)->not->toHaveKey('tokenInformation');
});

it('refunds and voids a capture through the capture resource, not the payment', function (): void {
    [$gateway, $http] = lifecycleGateway();
    $http->queueJson(['id' => 'rf_1', 'status' => 'PENDING']);

    $gateway->refundCapture(new RefundRequest(transactionId: 'cap_1', money: Money::minor(1000, 'USD')));

    expect($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/pts/v2/captures/cap_1/refunds');

    $http->queueJson(['id' => 'vd_1', 'status' => 'VOIDED']);
    $voided = $gateway->voidCapture(new VoidRequest(transactionId: 'cap_1'));

    expect($voided->status)->toBe(PaymentStatus::Voided)
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/pts/v2/captures/cap_1/voids');
});

it('voids a credit and a refund, which the payment path cannot reach', function (): void {
    [$gateway, $http] = lifecycleGateway();

    $gateway->voidCredit(new VoidRequest(transactionId: 'cr_1'));
    expect($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/credits/cr_1/voids');

    $gateway->voidRefund(new VoidRequest(transactionId: 'rf_1'));
    expect($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/refunds/rf_1/voids');
});

it('reverses a request whose reply never arrived, matching on the merchant transaction id', function (): void {
    [$gateway, $http] = lifecycleGateway();
    $http->queueJson(['id' => 'vd_9', 'status' => 'VOIDED']);

    $result = $gateway->timeoutVoid(new TimeoutVoidRequest(
        merchantTransactionId: 'mtid-charge-1',
        orderReference: 'ORDER-9',
    ));

    $request = $http->lastRequest();

    expect($result->status)->toBe(PaymentStatus::Voided)
        ->and($request?->url)->toBe('https://apitest.cybersource.com/pts/v2/voids')
        ->and($request?->header('v-c-idempotency-id'))->toBe('mtid-charge-1')
        ->and(lifecycleBody($http)['clientReferenceInformation'])->toBe([
            'code' => 'ORDER-9',
            'transactionId' => 'mtid-charge-1',
        ]);

    $http->queueJson(['id' => 'rv_9', 'status' => 'REVERSED']);
    $reversed = $gateway->timeoutReversal(new TimeoutVoidRequest(merchantTransactionId: 'mtid-charge-1'));

    expect($reversed->status)->toBe(PaymentStatus::Reversed)
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/reversals');
});

it('sends the merchant transaction id on the calls a timeout void can later reverse', function (): void {
    [$gateway, $http] = lifecycleGateway();

    $gateway->charge(new ChargeRequest(
        transientToken: 'tok', money: Money::minor(100, 'USD'),
        orderReference: 'ORDER-1', merchantTransactionId: 'mtid-1',
    ));
    expect(lifecycleBody($http)['clientReferenceInformation'])
        ->toBe(['code' => 'ORDER-1', 'transactionId' => 'mtid-1']);

    $gateway->capture(new CaptureRequest(
        transactionId: 'pay_1', money: Money::minor(100, 'USD'), merchantTransactionId: 'mtid-2',
    ));
    expect(lifecycleBody($http)['clientReferenceInformation'])
        ->toBe(['code' => 'pay_1', 'transactionId' => 'mtid-2']);

    $gateway->refund(new RefundRequest(
        transactionId: 'pay_1', money: Money::minor(100, 'USD'), merchantTransactionId: 'mtid-3',
    ));
    expect(lifecycleBody($http)['clientReferenceInformation'])
        ->toBe(['code' => 'pay_1', 'transactionId' => 'mtid-3']);
});

it('omits the transaction id entirely when none was supplied', function (): void {
    [$gateway, $http] = lifecycleGateway();

    $gateway->charge(new ChargeRequest(
        transientToken: 'tok', money: Money::minor(100, 'USD'), orderReference: 'ORDER-1',
    ));

    expect(lifecycleBody($http)['clientReferenceInformation'])->toBe(['code' => 'ORDER-1']);
});

it('re-checks a payment status with the processor', function (): void {
    [$gateway, $http] = lifecycleGateway();
    $http->queueJson(['id' => 'pay_1', 'status' => 'CAPTURED']);

    $result = $gateway->refreshPaymentStatus('pay_1');

    expect($result->status)->toBe(PaymentStatus::Captured)
        ->and($http->lastRequest()?->method)->toBe('POST')
        ->and($http->lastRequest()?->body)->toBe('')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/pts/v2/refresh-payment-status/pay_1');
});

it('throws when the gateway rejects a lifecycle call', function (): void {
    [$gateway, $http] = lifecycleGateway();
    $http->queueJson(['message' => 'Not voidable'], 400);

    expect(fn (): PaymentResult => $gateway->voidRefund(new VoidRequest(transactionId: 'rf_1')))
        ->toThrow(GatewayRequestException::class);
});
