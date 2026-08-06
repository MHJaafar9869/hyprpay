<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Exception;

use RuntimeException;

/**
 * Base exception for all payment gateway errors raised by the SDK.
 *
 * Every gateway-specific exception (missing credentials, unsupported gateway or
 * operation, failed API request, webhook verification failure) extends this type,
 * so callers can catch the whole family with a single `catch (GatewayException)`.
 */
class GatewayException extends RuntimeException {}
