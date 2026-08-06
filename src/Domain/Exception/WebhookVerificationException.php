<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Exception;

/**
 * Thrown when an inbound webhook fails signature or authenticity verification.
 *
 * Raised while validating a gateway callback whose signature cannot be trusted,
 * signalling that the payload must be rejected rather than processed.
 */
final class WebhookVerificationException extends GatewayException
{
    /**
     * Build the exception for a webhook whose signature could not be verified.
     *
     * @param  string  $reason  Short description of why verification failed (defaults to a signature mismatch).
     */
    public static function invalidSignature(string $reason = 'signature mismatch'): self
    {
        return new self("Webhook signature verification failed: {$reason}.");
    }
}
