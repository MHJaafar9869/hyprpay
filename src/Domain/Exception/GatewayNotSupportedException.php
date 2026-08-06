<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Exception;

/**
 * Thrown when a gateway is requested by name but no driver is registered for it.
 *
 * Typically raised while resolving a gateway from an unknown or misspelled
 * identifier string that does not map to any supported driver.
 */
final class GatewayNotSupportedException extends GatewayException
{
    /**
     * Build the exception for an unrecognized gateway identifier string.
     *
     * @param  string  $name  The unknown gateway name that has no registered driver.
     */
    public static function forName(string $name): self
    {
        return new self("No payment gateway driver is registered for '{$name}'.");
    }
}
