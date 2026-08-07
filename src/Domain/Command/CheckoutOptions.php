<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

/**
 * A typed, gateway-specific bag of checkout options carried by a CheckoutSessionRequest.
 *
 * Replaces the ambiguous free-form `array $options` with an object of clearly named,
 * typed fields per gateway (e.g. PayPalCheckoutOptions). A driver narrows the request's
 * options to its own implementation to read those fields; {@see toArray()} renders the
 * gateway's raw option keys so a request can still expose the options as an array for
 * gateways that have not yet been migrated to a typed DTO.
 */
interface CheckoutOptions
{
    /**
     * Render the options as the gateway's raw option-key array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
