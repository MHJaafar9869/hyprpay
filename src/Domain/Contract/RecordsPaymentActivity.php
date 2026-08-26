<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Contract;

use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;

/**
 * Write seam for capturing a single payment activity record.
 *
 * Implemented by the store-specific record actions (database, cache, discard) that the
 * recording listener invokes for every payment event. Kept separate from the read seam
 * {@see ReadsPaymentActivity} (CQRS) so writing and reading can vary independently.
 */
interface RecordsPaymentActivity
{
    /**
     * Persist a single PII-safe activity record produced from a payment domain event.
     *
     * @param  PaymentActivityRecord  $record  The record to store.
     */
    public function record(PaymentActivityRecord $record): void;
}
