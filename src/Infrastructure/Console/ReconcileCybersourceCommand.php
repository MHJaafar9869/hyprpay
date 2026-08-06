<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles CyberSource Unified Checkout transactions.
 *
 * Registered as `gateway:reconcile:cybersource_uc`.
 */
final class ReconcileCybersourceCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::CybersourceUnifiedCheckout;
    }
}
