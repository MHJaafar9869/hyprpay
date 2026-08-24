<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles Tamara transactions.
 *
 * Registered as `gateway:reconcile:tamara`.
 */
final class ReconcileTamaraCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::Tamara;
    }
}
