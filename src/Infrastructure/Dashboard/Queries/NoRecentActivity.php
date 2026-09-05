<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard\Queries;

use Hyprpay\Payments\Domain\Contract\ReadsPaymentActivity;

/**
 * Empty activity feed bound when the dashboard's activity store is disabled or set to "null".
 *
 * Lets the dashboard render without a feed — and without any null-checks at the call site —
 * by always returning no records.
 */
final readonly class NoRecentActivity implements ReadsPaymentActivity
{
    public function recent(int $limit, ?int $after = null): array
    {
        return [];
    }

    public function lifecycle(string $reference): array
    {
        return [];
    }
}
