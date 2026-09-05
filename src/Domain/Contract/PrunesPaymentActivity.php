<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Contract;

use DateTimeInterface;

/**
 * Maintenance seam for discarding activity the dashboard no longer needs.
 *
 * The third side of the store alongside {@see RecordsPaymentActivity} and
 * {@see ReadsPaymentActivity}: the cache driver is a bounded ring buffer that trims itself,
 * but the database driver grows for as long as payments keep happening, so something has to
 * set a retention window. Implemented per driver and driven by the `hyprpay:prune` command.
 */
interface PrunesPaymentActivity
{
    /**
     * Discard every record captured before `$before`.
     *
     * @param  DateTimeInterface  $before  The cutoff; anything older is removed.
     * @return int The number of rows discarded.
     */
    public function prune(DateTimeInterface $before): int;
}
