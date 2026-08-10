<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles MPGS orders.
 *
 * Registered as `gateway:reconcile:mpgs`.
 */
final class ReconcileMpgsCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::Mpgs;
    }
}
