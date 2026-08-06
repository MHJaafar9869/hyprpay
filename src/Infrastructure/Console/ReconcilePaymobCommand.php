<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles Paymob transactions.
 *
 * Registered as `gateway:reconcile:paymob`.
 */
final class ReconcilePaymobCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::Paymob;
    }
}
