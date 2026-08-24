<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Contract;

use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;

/**
 * Port for the store the monitoring dashboard reads its activity feed from.
 *
 * Decouples recording and displaying gateway activity from where it lives. The default
 * adapter (CachePaymentActivityRepository) keeps a bounded ring buffer of the most recent
 * records in the cache — no database required — while a host can bind a durable,
 * database-backed implementation to keep full history without touching the dashboard.
 */
interface PaymentActivityRepository
{
    /**
     * Persist a single activity record produced from a payment domain event.
     *
     * @param  PaymentActivityRecord  $record  The PII-safe record to store.
     */
    public function record(PaymentActivityRecord $record): void;

    /**
     * Read the most recent records, newest first.
     *
     * @param  int  $limit  The maximum number of records to return.
     * @return list<PaymentActivityRecord> The stored records, newest first, capped at $limit.
     */
    public function recent(int $limit): array;

    /**
     * Discard every stored record.
     */
    public function clear(): void;
}
