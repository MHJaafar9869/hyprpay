<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Contract;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;

/**
 * Port that supplies gateway credentials for a given gateway.
 *
 * Lets the factory build a driver without knowing where credentials come from;
 * the default adapter (ConfigCredentialResolver) reads them from application config.
 */
interface CredentialResolver
{
    /**
     * Resolve the credentials configured for the given gateway.
     *
     * @param  GatewayName  $gateway  The gateway whose credentials are requested.
     * @return GatewayCredentials The credentials needed to authenticate against that gateway.
     */
    public function resolve(GatewayName $gateway): GatewayCredentials;
}
