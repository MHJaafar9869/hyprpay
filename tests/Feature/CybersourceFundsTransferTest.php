<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\PullFundsRequest;
use Hyprpay\Payments\Domain\Command\PushFundsRequest;
use Hyprpay\Payments\Domain\Enum\BusinessApplicationId;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Enum\TransferPartyType;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Result\FundsTransferResult;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\ValueObject\TransferParty;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function transferGateway(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function transferBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

function aSender(): TransferParty
{
    return new TransferParty(
        firstName: 'Ada', lastName: 'Lovelace', type: TransferPartyType::Individual,
        address1: '1 Market St', locality: 'London', postalCode: 'EC1A 1BB', country: 'GB',
        dateOfBirth: '19151210', referenceNumber: 'SENDER-1',
        personalIdentification: ['type' => 'PASSPORT', 'id' => 'X1234567'],
    );
}

it('pushes funds to a recipient card with the transfer purpose declared', function (): void {
    [$gateway, $http] = transferGateway();
    $http->queueJson([
        'id' => 'oct_1',
        'status' => 'AUTHORIZED',
        'reconciliationId' => 'recon_1',
        'processorInformation' => ['approvalCode' => '831000'],
    ]);

    $result = $gateway->pushFunds(new PushFundsRequest(
        money: Money::minor(25000, 'USD'),
        cardNumber: '4111111111111111',
        expirationMonth: '12',
        expirationYear: '2031',
        businessApplicationId: BusinessApplicationId::PersonToPerson,
        sender: aSender(),
        recipient: new TransferParty(firstName: 'Grace', lastName: 'Hopper', address1: '2 Navy Yard', postalCode: '20374', country: 'US'),
        orderReference: 'TRANSFER-1',
        merchantTransactionId: 'mtid-push-1',
    ));

    $request = $http->lastRequest();
    $body = transferBody($http);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Authorized)
        ->and($result->transferId)->toBe('oct_1')
        ->and($result->reconciliationId)->toBe('recon_1')
        ->and($result->approvalCode)->toBe('831000')
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/pts/v1/push-funds-transfer')
        ->and($request?->header('v-c-idempotency-id'))->toBe('mtid-push-1')
        ->and($body['processingInformation']['businessApplicationId'])->toBe('PP')
        ->and($body['orderInformation']['amountDetails'])->toBe(['totalAmount' => '250.00', 'currency' => 'USD'])
        ->and($body['recipientInformation']['paymentInformation']['card'])->toBe([
            'number' => '4111111111111111', 'expirationMonth' => '12', 'expirationYear' => '2031',
        ])
        ->and($body['recipientInformation']['firstName'])->toBe('Grace')
        ->and($body['clientReferenceInformation'])->toBe(['code' => 'TRANSFER-1', 'transactionId' => 'mtid-push-1']);
});

it('sends the sender identification the networks screen a money transfer against', function (): void {
    [$gateway, $http] = transferGateway();

    $gateway->pushFunds(new PushFundsRequest(
        money: Money::minor(100, 'USD'),
        cardNumber: '4111111111111111',
        businessApplicationId: BusinessApplicationId::PersonToPerson,
        sender: aSender(),
    ));

    $sender = transferBody($http)['senderInformation'];

    expect($sender['firstName'])->toBe('Ada')
        ->and($sender['type'])->toBe('I')
        ->and($sender['dateOfBirth'])->toBe('19151210')
        ->and($sender['referenceNumber'])->toBe('SENDER-1')
        ->and($sender['personalIdentification'])->toBe(['type' => 'PASSPORT', 'id' => 'X1234567'])
        ->and($sender['country'])->toBe('GB');
});

it('omits the sender-only fields from the recipient block, which does not accept them', function (): void {
    [$gateway, $http] = transferGateway();

    $gateway->pushFunds(new PushFundsRequest(
        money: Money::minor(100, 'USD'),
        cardNumber: '4111111111111111',
        recipient: aSender(),   // same party object, recipient side
    ));

    $recipient = transferBody($http)['recipientInformation'];

    expect($recipient['firstName'])->toBe('Ada')
        ->and($recipient)->not->toHaveKey('dateOfBirth')
        ->and($recipient)->not->toHaveKey('referenceNumber')
        ->and($recipient)->not->toHaveKey('vatRegistrationNumber');
});

it('pulls funds from the sender card, carrying the same purpose as the push', function (): void {
    [$gateway, $http] = transferGateway();
    $http->queueJson(['id' => 'aft_1', 'status' => 'AUTHORIZED', 'reconciliationId' => 'recon_2']);

    $result = $gateway->pullFunds(new PullFundsRequest(
        money: Money::minor(25000, 'USD'),
        cardNumber: '5555555555554444',
        expirationMonth: '01',
        expirationYear: '2030',
        securityCode: '123',
        businessApplicationId: BusinessApplicationId::PersonToPerson,
        sender: aSender(),
        orderReference: 'TRANSFER-1',
    ));

    $body = transferBody($http);

    expect($result->transferId)->toBe('aft_1')
        ->and($result->reconciliationId)->toBe('recon_2')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v1/pull-funds-transfer')
        ->and($body['processingInformation']['businessApplicationId'])->toBe('PP')
        ->and($body['senderInformation']['paymentInformation']['card'])->toBe([
            'number' => '5555555555554444', 'expirationMonth' => '01', 'expirationYear' => '2030', 'securityCode' => '123',
        ])
        ->and($body['senderInformation']['firstName'])->toBe('Ada')
        ->and($body)->not->toHaveKey('recipientInformation');
});

it('transfers from a vaulted instrument instead of a raw card', function (): void {
    [$gateway, $http] = transferGateway();

    $gateway->pushFunds(new PushFundsRequest(money: Money::minor(100, 'USD'), paymentInstrumentId: 'pi_1'));

    $payment = transferBody($http)['recipientInformation']['paymentInformation'];

    expect($payment)->toBe(['paymentInstrument' => ['id' => 'pi_1']])
        ->and(json_encode(transferBody($http)))->not->toContain('"number"');
});

it('refunds and reverses a pull, for a transfer that failed after the sender was debited', function (): void {
    [$gateway, $http] = transferGateway();
    $http->queueJson(['id' => 'rf_1', 'status' => 'AUTHORIZED']);

    $refund = $gateway->refundPullFunds('aft_1', 'TRANSFER-1');

    expect($refund->success)->toBeTrue()
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/pts/v1/pull-funds-transfer/aft_1/refund')
        ->and(transferBody($http)['clientReferenceInformation']['code'])->toBe('TRANSFER-1');

    $http->queueJson(['id' => 'rv_1', 'status' => 'REVERSED']);
    $reversal = $gateway->reversePullFunds('aft_1');

    expect($reversal->status)->toBe(PaymentStatus::Reversed)
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/pts/v1/pull-funds-transfer/aft_1/reversal');
});

it('reports a declined leg without treating it as a failed request', function (): void {
    [$gateway, $http] = transferGateway();
    $http->queueJson(['id' => 'oct_2', 'status' => 'DECLINED', 'errorInformation' => ['reason' => 'INSUFFICIENT_FUND']]);

    $result = $gateway->pushFunds(new PushFundsRequest(money: Money::minor(100, 'USD'), cardNumber: '4111111111111111'));

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(PaymentStatus::Declined)
        ->and($result->code)->toBe('INSUFFICIENT_FUND');
});

it('quotes an fx rate and queries a payout', function (): void {
    [$gateway, $http] = transferGateway();
    $http->queueJson(['fxQuote' => ['rate' => '0.79']]);

    expect(data_get($gateway->payoutFxRates(['fxQuote' => ['currency' => 'GBP']]), 'fxQuote.rate'))->toBe('0.79')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/pts/v2/payouts/fx-rates');

    $http->queueJson(['id' => 'oct_1', 'status' => 'AUTHORIZED']);
    expect($gateway->queryPayout('oct_1')['status'])->toBe('AUTHORIZED')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/pts/v2/payouts/transaction-query/oct_1');
});

it('separates money transfers from funds disbursements', function (): void {
    expect(BusinessApplicationId::PersonToPerson->isMoneyTransfer())->toBeTrue()
        ->and(BusinessApplicationId::PersonToPerson->isFundsDisbursement())->toBeFalse()
        ->and(BusinessApplicationId::PayrollAndPension->isFundsDisbursement())->toBeTrue()
        ->and(BusinessApplicationId::PayrollAndPension->isMoneyTransfer())->toBeFalse()
        ->and(BusinessApplicationId::cases())->toHaveCount(20)
        ->and(BusinessApplicationId::moneyTransfers())->toHaveCount(7)
        ->and(BusinessApplicationId::fundsDisbursements())->toHaveCount(13)
        ->and(BusinessApplicationId::AccountToAccount->label())->toBe('Account to account')
        ->and(BusinessApplicationId::from('PD')->label())->toBe('Payroll or pension disbursement');
});

it('throws when the transfer service rejects the request', function (): void {
    [$gateway, $http] = transferGateway();
    $http->queueJson(['message' => 'Not entitled'], 400);

    expect(fn (): FundsTransferResult => $gateway->pushFunds(
        new PushFundsRequest(money: Money::minor(100, 'USD'), cardNumber: '4111111111111111'),
    ))->toThrow(GatewayRequestException::class);
});
