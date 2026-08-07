<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles PayTabs transactions.
 *
 * Registered as `gateway:reconcile:paytabs`.
 */
final class ReconcilePaytabsCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::Paytabs;
    }
}
