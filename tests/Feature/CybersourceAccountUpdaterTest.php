<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CreateAccountUpdaterBatchRequest;
use Hyprpay\Payments\Domain\Enum\AccountUpdaterBatchStatus;
use Hyprpay\Payments\Domain\Enum\AccountUpdaterBatchType;
use Hyprpay\Payments\Domain\ValueObject\AccountUpdaterToken;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function updaterGateway(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function updaterBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('submits vault token ids for refresh, sending no card data', function (): void {
    [$gateway, $http] = updaterGateway();
    $http->queueJson(['batchId' => 'batch_1', 'status' => 'RECEIVED'], 202);

    $batch = $gateway->createAccountUpdaterBatch(CreateAccountUpdaterBatchRequest::forTokenIds(
        ['pi_1', 'pi_2', 'ii_9'],
        merchantReference: 'NIGHTLY-2026-09-01',
    ));

    $request = $http->lastRequest();
    $body = updaterBody($http);

    expect($batch->batchId)->toBe('batch_1')
        ->and($batch->status)->toBe(AccountUpdaterBatchStatus::Received)
        ->and($batch->status?->isInProgress())->toBeTrue()
        ->and($batch->isComplete())->toBeFalse()
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/accountupdater/v1/batches')
        ->and($request?->header('Signature'))->not->toBeNull()
        ->and($body['type'])->toBe('oneOff')
        ->and($body['merchantReference'])->toBe('NIGHTLY-2026-09-01')
        ->and($body['included']['tokens'])->toBe([['id' => 'pi_1'], ['id' => 'pi_2'], ['id' => 'ii_9']])
        ->and(json_encode($body))->not->toContain('number');
});

it('sends the expiry on file alongside a token when supplied', function (): void {
    [$gateway, $http] = updaterGateway();

    $gateway->createAccountUpdaterBatch(new CreateAccountUpdaterBatchRequest(
        tokens: [new AccountUpdaterToken('pi_1', '12', '2026'), new AccountUpdaterToken('pi_2')],
    ));

    expect(updaterBody($http)['included']['tokens'])->toBe([
        ['id' => 'pi_1', 'expirationMonth' => '12', 'expirationYear' => '2026'],
        ['id' => 'pi_2'],
    ]);
});

it('submits amex cards as a registration batch', function (): void {
    [$gateway, $http] = updaterGateway();

    $gateway->createAccountUpdaterBatch(CreateAccountUpdaterBatchRequest::forTokenIds(
        ['pi_amex'],
        type: AccountUpdaterBatchType::AmexRegistration,
    ));

    expect(updaterBody($http)['type'])->toBe('amexRegistration')
        ->and(updaterBody($http))->not->toHaveKey('merchantReference');
});

it('polls a batch for its totals and reports what the networks changed', function (): void {
    [$gateway, $http] = updaterGateway();
    $http->queueJson([
        'batchId' => 'batch_1',
        'status' => 'COMPLETED',
        'batchCreatedDate' => '2026-09-01T02:00:00Z',
        'batchSource' => 'TOKEN_API',
        'merchantReference' => 'NIGHTLY-2026-09-01',
        'totals' => [
            'acceptedRecords' => 100,
            'rejectedRecords' => 2,
            'updatedRecords' => 7,
            'caResponses' => 98,
        ],
    ]);

    $batch = $gateway->getAccountUpdaterBatchStatus('batch_1');

    expect($batch->isComplete())->toBeTrue()
        ->and($batch->hasUpdates())->toBeTrue()
        ->and($batch->acceptedRecords)->toBe(100)
        ->and($batch->rejectedRecords)->toBe(2)
        ->and($batch->updatedRecords)->toBe(7)
        ->and($batch->networkResponses)->toBe(98)
        ->and($batch->source)->toBe('TOKEN_API')
        ->and($batch->createdDate)->toBe('2026-09-01T02:00:00Z')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/accountupdater/v1/batches/batch_1/status');
});

it('reports a completed batch that changed nothing as having no updates', function (): void {
    [$gateway, $http] = updaterGateway();
    $http->queueJson(['batchId' => 'batch_2', 'status' => 'COMPLETED', 'totals' => ['acceptedRecords' => 50]]);

    $batch = $gateway->getAccountUpdaterBatchStatus('batch_2');

    expect($batch->isComplete())->toBeTrue()
        ->and($batch->hasUpdates())->toBeFalse()
        ->and($batch->updatedRecords)->toBe(0);
});

it('flags a rejected batch as failed and not in progress', function (): void {
    [$gateway, $http] = updaterGateway();
    $http->queueJson(['batchId' => 'batch_3', 'status' => 'REJECTED']);

    $batch = $gateway->getAccountUpdaterBatchStatus('batch_3');

    expect($batch->status?->isFailed())->toBeTrue()
        ->and($batch->status?->isInProgress())->toBeFalse()
        ->and($batch->isComplete())->toBeFalse();
});

it('fetches the per-card batch report', function (): void {
    [$gateway, $http] = updaterGateway();
    $http->queueJson(['batchId' => 'batch_1', 'records' => [
        ['id' => 'pi_1', 'response' => ['code' => 'NAN'], 'card' => ['expirationYear' => '2032']],
    ]]);

    $report = $gateway->getAccountUpdaterBatchReport('batch_1');

    expect(data_get($report, 'records.0.response.code'))->toBe('NAN')
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/accountupdater/v1/batches/batch_1/report');
});

it('lists batches over an optional date range', function (): void {
    [$gateway, $http] = updaterGateway();
    $http->queueJson(['_embedded' => ['batches' => [
        ['batchId' => 'batch_1', 'status' => 'COMPLETED'],
        ['batchId' => 'batch_2', 'status' => 'PROCESSING'],
    ]]]);

    $batches = $gateway->listAccountUpdaterBatches(limit: 50, fromDate: '20260901T000000Z');

    expect($batches)->toHaveCount(2)
        ->and($batches[0]->isComplete())->toBeTrue()
        ->and($batches[1]->status)->toBe(AccountUpdaterBatchStatus::Processing)
        ->and($http->lastRequest()?->url)
        ->toBe('https://apitest.cybersource.com/accountupdater/v1/batches?offset=0&limit=50&fromDate=20260901T000000Z');
});

it('returns an empty list when no batches match', function (): void {
    [$gateway] = updaterGateway();

    expect($gateway->listAccountUpdaterBatches())->toBe([]);
});
