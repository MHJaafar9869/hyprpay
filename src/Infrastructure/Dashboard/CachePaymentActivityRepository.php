<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard;

use Hyprpay\Payments\Domain\Contract\PaymentActivityRepository;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * PaymentActivityRepository adapter backed by a bounded ring buffer in the cache.
 *
 * Keeps only the most recent `limit` records under a single cache key — no database and
 * no migration — so the dashboard works out of the box in any host. New records are
 * prepended and the tail beyond `limit` is dropped, so reads are already newest-first.
 * Only PII-safe {@see PaymentActivityRecord}s are stored, serialised to primitives.
 */
final readonly class CachePaymentActivityRepository implements PaymentActivityRepository
{
    /**
     * @param  CacheFactory  $cache  Resolves the cache store the buffer lives in.
     * @param  string|null  $store  Cache store name to use, or null for the default store.
     * @param  string  $key  Cache key the ring buffer is stored under.
     * @param  int  $limit  Maximum number of records retained in the buffer.
     */
    public function __construct(
        private CacheFactory $cache,
        private ?string $store,
        private string $key,
        private int $limit,
    ) {}

    public function record(PaymentActivityRecord $record): void
    {
        $rows = $this->rows();
        array_unshift($rows, $record->toArray());

        $this->store()->forever($this->key, array_slice($rows, 0, max(1, $this->limit)));
    }

    public function recent(int $limit): array
    {
        $records = array_filter(array_map(
            PaymentActivityRecord::fromArray(...),
            $this->rows(),
        ));

        return array_values(array_slice($records, 0, max(0, $limit)));
    }

    public function clear(): void
    {
        $this->store()->forget($this->key);
    }

    /**
     * Read the raw stored rows, tolerating a missing or malformed buffer.
     *
     * @return list<array<array-key, mixed>>
     */
    private function rows(): array
    {
        $stored = $this->store()->get($this->key, []);

        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, is_array(...)));
    }

    /**
     * Resolve the configured cache store (or the default when none is set).
     */
    private function store(): Repository
    {
        return $this->cache->store($this->store);
    }
}
