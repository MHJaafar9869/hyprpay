<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard;

use Hyprpay\Payments\Domain\Contract\PaymentActivityRepository;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;

/**
 * No-op PaymentActivityRepository bound when the dashboard's activity store is disabled.
 *
 * Lets the dashboard render without an activity feed — and keeps the recorder listener a
 * no-op cost — without any null-checks at the call sites: records are discarded and reads
 * always return an empty feed.
 */
final readonly class NullPaymentActivityRepository implements PaymentActivityRepository
{
    public function record(PaymentActivityRecord $record): void
    {
        //
    }

    public function recent(int $limit): array
    {
        return [];
    }

    public function clear(): void
    {
        //
    }
}
