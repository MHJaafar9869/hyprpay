<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Infrastructure\Dashboard\CachePaymentActivityRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Build a cache-backed activity repository over an in-memory array store.
 */
function cacheActivityRepository(int $limit): CachePaymentActivityRepository
{
    $store = new Repository(new ArrayStore);

    $factory = new class($store) implements Factory
    {
        public function __construct(private readonly CacheRepository $store) {}

        public function store($name = null): CacheRepository
        {
            return $this->store;
        }
    };

    return new CachePaymentActivityRepository($factory, null, 'hyprpay:test', $limit);
}

/**
 * Build a minimal activity record identified by its transaction id.
 */
function activityRecord(string $id): PaymentActivityRecord
{
    return PaymentActivityRecord::make('PaymentCharged', GatewayName::Fawry, null, true, 'ORD', $id, null, null, '2026-08-24T00:00:00+00:00');
}

/**
 * @param  list<PaymentActivityRecord>  $records
 * @return list<string|null>
 */
function transactionIds(array $records): array
{
    return array_map(static fn (PaymentActivityRecord $r): ?string => $r->transactionId, $records);
}

it('returns recorded activity newest first', function (): void {
    $repository = cacheActivityRepository(10);
    $repository->record(activityRecord('a'));
    $repository->record(activityRecord('b'));

    expect(transactionIds($repository->recent(10)))->toBe(['b', 'a']);
});

it('caps the buffer at the configured limit, dropping the oldest', function (): void {
    $repository = cacheActivityRepository(2);
    $repository->record(activityRecord('a'));
    $repository->record(activityRecord('b'));
    $repository->record(activityRecord('c'));

    $recent = $repository->recent(10);

    expect($recent)->toHaveCount(2)
        ->and(transactionIds($recent))->toBe(['c', 'b']);
});

it('honours the read limit independently of the buffer size', function (): void {
    $repository = cacheActivityRepository(10);
    $repository->record(activityRecord('a'));
    $repository->record(activityRecord('b'));
    $repository->record(activityRecord('c'));

    expect($repository->recent(2))->toHaveCount(2);
});

it('clears the buffer', function (): void {
    $repository = cacheActivityRepository(10);
    $repository->record(activityRecord('a'));
    $repository->clear();

    expect($repository->recent(10))->toBe([]);
});
