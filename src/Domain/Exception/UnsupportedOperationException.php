<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Exception;

use Hyprpay\Payments\Domain\Enum\GatewayName;

/**
 * Thrown when a gateway driver does not implement a requested operation.
 *
 * Raised by the abstract gateway's default stubs when a concrete driver has not
 * overridden an operation (e.g. refund, void) that the caller attempted to invoke.
 */
final class UnsupportedOperationException extends GatewayException
{
    /**
     * Build the exception for an operation a gateway driver does not implement.
     *
     * @param  GatewayName  $gateway  The gateway whose driver lacks the operation.
     * @param  string  $operation  The name of the unsupported operation that was invoked.
     */
    public static function forOperation(GatewayName $gateway, string $operation): self
    {
        return new self("The {$gateway->label()} gateway does not support the '{$operation}' operation.");
    }
}
