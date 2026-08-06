<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles PayLink transactions.
 *
 * Registered as `gateway:reconcile:paylink`.
 */
final class ReconcilePaylinkCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::Paylink;
    }
}
