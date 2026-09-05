<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard\Actions;

use DateTimeInterface;
use Hyprpay\Payments\Domain\Contract\PrunesPaymentActivity;

/**
 * The no-op pruner, bound for the drivers that need no retention window.
 *
 * The cache store is a fixed-size ring buffer that drops its own tail, and the null store
 * keeps nothing at all, so for both of them pruning is already handled — this reports zero
 * rather than leaving the command without an implementation to resolve.
 */
final readonly class PruneNothing implements PrunesPaymentActivity
{
    public function prune(DateTimeInterface $before): int
    {
        return 0;
    }
}
