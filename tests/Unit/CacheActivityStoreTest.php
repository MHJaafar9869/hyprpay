<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Infrastructure\Dashboard\Actions\RecordActivityToCache;
use Hyprpay\Payments\Infrastructure\Dashboard\Queries\RecentActivityFromCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Build a cache factory over a single shared in-memory array store.
 */
function cacheFactory(): Factory
{
    $store = new Repository(new ArrayStore);

    return new class($store) implements Factory
    {
        public function __construct(private readonly CacheRepository $store) {}

        public function store($name = null): CacheRepository
        {
            return $this->store;
        }
    };
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

it('reads recorded activity newest first', function (): void {
    $factory = cacheFactory();
    $write = new RecordActivityToCache($factory, null, 'hyprpay:test', 10);
    $read = new RecentActivityFromCache($factory, null, 'hyprpay:test');

    $write->record(activityRecord('a'));
    $write->record(activityRecord('b'));

    expect(transactionIds($read->recent(10)))->toBe(['b', 'a']);
});

it('caps the buffer at the configured limit, dropping the oldest', function (): void {
    $factory = cacheFactory();
    $write = new RecordActivityToCache($factory, null, 'hyprpay:test', 2);
    $read = new RecentActivityFromCache($factory, null, 'hyprpay:test');

    $write->record(activityRecord('a'));
    $write->record(activityRecord('b'));
    $write->record(activityRecord('c'));

    expect(transactionIds($read->recent(10)))->toBe(['c', 'b']);
});

it('honours the read limit independently of the buffer size', function (): void {
    $factory = cacheFactory();
    $write = new RecordActivityToCache($factory, null, 'hyprpay:test', 10);
    $read = new RecentActivityFromCache($factory, null, 'hyprpay:test');

    $write->record(activityRecord('a'));
    $write->record(activityRecord('b'));
    $write->record(activityRecord('c'));

    expect($read->recent(2))->toHaveCount(2);
});
