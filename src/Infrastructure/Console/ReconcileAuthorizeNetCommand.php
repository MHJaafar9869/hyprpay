<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Artisan command that reconciles Authorize.Net transactions.
 *
 * Registered as `gateway:reconcile:authorize_net`.
 */
final class ReconcileAuthorizeNetCommand extends ReconcileCommand
{
    protected function gateway(): GatewayName
    {
        return GatewayName::AuthorizeNet;
    }
}
