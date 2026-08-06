<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles Fawry transactions.
 *
 * Registered as `gateway:reconcile:fawry`.
 */
final class ReconcileFawryCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::Fawry;
    }
}
