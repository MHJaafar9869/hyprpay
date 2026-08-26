<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Contract;

use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;

/**
 * Read seam for the monitoring dashboard's activity feed.
 *
 * Implemented by the store-specific recent-activity queries (database, cache, none). Kept
 * separate from the write seam {@see RecordsPaymentActivity} (CQRS): the dashboard only
 * ever reads, and each store can back reads and writes differently.
 */
interface ReadsPaymentActivity
{
    /**
     * Read the most recent records, newest first.
     *
     * @param  int  $limit  The maximum number of records to return.
     * @return list<PaymentActivityRecord> The stored records, newest first, capped at $limit.
     */
    public function recent(int $limit): array;

    /**
     * Read every recorded event for one payment reference, oldest first (its full lifecycle).
     *
     * Matches the reference against both the order reference and the transaction id, so a row's
     * order OR its transaction id resolves the same lifecycle.
     *
     * @param  string  $reference  The order reference or transaction id to trace.
     * @return list<PaymentActivityRecord> Every matching record, oldest first.
     */
    public function lifecycle(string $reference): array;
}
