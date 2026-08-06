<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Exception;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Thrown when a gateway's required credentials are absent or incomplete.
 *
 * Raised by the credential resolver when configuration for a gateway is missing
 * or does not contain all the fields needed to authenticate against its API.
 */
final class MissingCredentialsException extends GatewayException
{
    /**
     * Build the exception for a gateway whose credentials are missing or incomplete.
     *
     * @param  GatewayName  $gateway  The gateway whose credentials could not be resolved.
     */
    public static function forGateway(GatewayName $gateway): self
    {
        return new self("Missing or incomplete credentials for the {$gateway->label()} gateway.");
    }
}
