<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles PayPal transactions.
 *
 * Registered as `gateway:reconcile:paypal`.
 */
final class ReconcilePayPalCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::PayPal;
    }
}
