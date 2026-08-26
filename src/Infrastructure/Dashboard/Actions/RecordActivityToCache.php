<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard\Actions;

use Hyprpay\Payments\Domain\Contract\RecordsPaymentActivity;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Infrastructure\Dashboard\Queries\RecentActivityFromCache;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * Record action backed by a bounded ring buffer in the cache.
 *
 * Keeps only the most recent `limit` records under a single cache key — no database and no
 * migration. New records are prepended and the tail beyond `limit` is dropped, so the
 * paired {@see RecentActivityFromCache}
 * read already returns newest-first. Only PII-safe fields are stored, serialised to primitives.
 */
final readonly class RecordActivityToCache implements RecordsPaymentActivity
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
        $stored = $this->store()->get($this->key, []);
        $rows = is_array($stored) ? array_values(array_filter($stored, is_array(...))) : [];

        array_unshift($rows, $record->toArray());

        $this->store()->forever($this->key, array_slice($rows, 0, max(1, $this->limit)));
    }

    /**
     * Resolve the configured cache store (or the default when none is set).
     */
    private function store(): Repository
    {
        return $this->cache->store($this->store);
    }
}
