<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\ValidateBankAccountRequest;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Result\BankAccountValidationResult;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function bavsGateway(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function bavsBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('validates a bank account from raw routing and account numbers', function (): void {
    [$gateway, $http] = bavsGateway();
    $http->queueJson([
        'requestId' => 'val_1',
        'submitTimeUtc' => '2026-09-01T101500Z',
        'clientReferenceInformation' => ['code' => 'ORDER-1'],
        'bankAccountValidation' => [
            'resultCode' => 0,
            'rawValidationCode' => 12,
            'resultMessage' => 'Open Valid Account',
        ],
    ]);

    $result = $gateway->validateBankAccount(new ValidateBankAccountRequest(
        routingNumber: '071000013',
        accountNumber: '4100',
        orderReference: 'ORDER-1',
    ));

    $request = $http->lastRequest();
    $body = bavsBody($http);

    expect($result->isValid())->toBeTrue()
        ->and($result->isInconclusive())->toBeFalse()
        ->and($result->resultCode)->toBe(0)
        ->and($result->rawValidationCode)->toBe(12)
        ->and($result->resultMessage)->toBe('Open Valid Account')
        ->and($result->requestId)->toBe('val_1')
        ->and($result->orderReference)->toBe('ORDER-1')
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/bavs/v1/account-validations')
        ->and($request?->header('Signature'))->not->toBeNull()
        ->and($body['processingInformation']['validationLevel'])->toBe(1)
        ->and($body['paymentInformation']['bank'])->toBe([
            'routingNumber' => '071000013',
            'account' => ['number' => '4100'],
        ])
        ->and($body['clientReferenceInformation']['code'])->toBe('ORDER-1');
});

it('validates a vaulted account by token without sending any bank details', function (): void {
    [$gateway, $http] = bavsGateway();

    $gateway->validateBankAccount(new ValidateBankAccountRequest(customerId: 'cust_1'));

    $paymentInformation = bavsBody($http)['paymentInformation'];

    expect($paymentInformation['customer'])->toBe(['id' => 'cust_1'])
        ->and($paymentInformation)->not->toHaveKey('bank');
});

it('sends the instrument tokens when validating a specific stored account', function (): void {
    [$gateway, $http] = bavsGateway();

    $gateway->validateBankAccount(new ValidateBankAccountRequest(
        customerId: 'cust_1',
        paymentInstrumentId: 'pi_1',
        instrumentIdentifierId: 'ii_1',
    ));

    $paymentInformation = bavsBody($http)['paymentInformation'];

    expect($paymentInformation['paymentInstrument'])->toBe(['id' => 'pi_1'])
        ->and($paymentInformation['instrumentIdentifier'])->toBe(['id' => 'ii_1']);
});

it('omits a half-supplied bank block rather than sending one the service would reject', function (): void {
    [$gateway, $http] = bavsGateway();

    $gateway->validateBankAccount(new ValidateBankAccountRequest(
        routingNumber: '071000013',
        customerId: 'cust_1',
    ));

    expect(bavsBody($http)['paymentInformation'])->not->toHaveKey('bank');
});

it('treats every result code other than the documented pass as not validated', function (int $resultCode): void {
    [$gateway, $http] = bavsGateway();
    $http->queueJson(['bankAccountValidation' => ['resultCode' => $resultCode, 'rawValidationCode' => 13]]);

    $result = $gateway->validateBankAccount(new ValidateBankAccountRequest(
        routingNumber: '071000013', accountNumber: '4100',
    ));

    expect($result->isValid())->toBeFalse()
        ->and($result->isInconclusive())->toBeFalse();
})->with([4, 98, 99]);

it('flags an unavailable or unknown validation as inconclusive rather than a bad account', function (int $rawCode): void {
    [$gateway, $http] = bavsGateway();
    $http->queueJson(['bankAccountValidation' => ['resultCode' => 99, 'rawValidationCode' => $rawCode]]);

    $result = $gateway->validateBankAccount(new ValidateBankAccountRequest(
        routingNumber: '071000013', accountNumber: '4100',
    ));

    expect($result->isInconclusive())->toBeTrue()
        ->and($result->isValid())->toBeFalse();
})->with([
    BankAccountValidationResult::RAW_UNKNOWN_ERROR,
    BankAccountValidationResult::RAW_SERVICE_UNAVAILABLE,
]);

it('reports an absent validation block as neither valid nor inconclusive', function (): void {
    [$gateway] = bavsGateway();

    $result = $gateway->validateBankAccount(new ValidateBankAccountRequest(
        routingNumber: '071000013', accountNumber: '4100',
    ));

    expect($result->resultCode)->toBeNull()
        ->and($result->rawValidationCode)->toBeNull()
        ->and($result->isValid())->toBeFalse()
        ->and($result->isInconclusive())->toBeFalse();
});

it('throws when the validation service rejects the request', function (): void {
    [$gateway, $http] = bavsGateway();
    $http->queueJson(['message' => 'Invalid routing number'], 400);

    expect(fn (): BankAccountValidationResult => $gateway->validateBankAccount(
        new ValidateBankAccountRequest(routingNumber: 'x', accountNumber: 'y'),
    ))->toThrow(GatewayRequestException::class);
});
