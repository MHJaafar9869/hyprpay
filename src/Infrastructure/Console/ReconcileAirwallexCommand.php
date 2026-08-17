<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles Airwallex transactions.
 *
 * Registered as `gateway:reconcile:airwallex`.
 */
final class ReconcileAirwallexCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::Airwallex;
    }
}
