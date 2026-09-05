<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard\Queries;

use Hyprpay\Payments\Domain\Contract\ReadsPaymentActivity;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Infrastructure\Dashboard\Actions\RecordActivityToCache;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * Reads the most recent records (newest first) from the cache ring buffer written by
 * {@see RecordActivityToCache}.
 */
final readonly class RecentActivityFromCache implements ReadsPaymentActivity
{
    /**
     * @param  CacheFactory  $cache  Resolves the cache store the buffer lives in.
     * @param  string|null  $store  Cache store name to use, or null for the default store.
     * @param  string  $key  Cache key the ring buffer is stored under.
     */
    public function __construct(
        private CacheFactory $cache,
        private ?string $store,
        private string $key,
    ) {}

    public function recent(int $limit, ?int $after = null): array
    {
        return array_values(array_slice($this->records(), 0, max(0, $limit)));
    }

    public function lifecycle(string $reference): array
    {
        $matching = array_filter(
            $this->records(),
            static fn (PaymentActivityRecord $record): bool => $record->orderReference === $reference || $record->transactionId === $reference,
        );

        return array_reverse(array_values($matching));
    }

    /**
     * Rehydrate the buffered records, newest first, skipping any malformed row.
     *
     * @return list<PaymentActivityRecord>
     */
    private function records(): array
    {
        $stored = $this->store()->get($this->key, []);
        $rows = is_array($stored) ? array_values(array_filter($stored, is_array(...))) : [];

        return array_values(array_filter(array_map(PaymentActivityRecord::fromArray(...), $rows)));
    }

    /**
     * Resolve the configured cache store (or the default when none is set).
     */
    private function store(): Repository
    {
        return $this->cache->store($this->store);
    }
}
