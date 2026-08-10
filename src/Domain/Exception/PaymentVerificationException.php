<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Exception;

/**
 * Thrown when a client-supplied orchestrated-payment result JWT cannot be trusted.
 *
 * Raised while confirming a Unified Checkout v1 (autoProcessing / completeMandate)
 * payment: the signed result JWT must cryptographically verify against the public key
 * from the capture context and match the order it was minted for. Any failure — a
 * missing verification key, a bad or expired signature, or an issuer/order/amount
 * mismatch — surfaces as this exception so the result is rejected rather than trusted.
 */
final class PaymentVerificationException extends GatewayException
{
    /**
     * Build the exception for a capture context that carries no usable verification key.
     *
     * @param  string  $reason  Short description of why the key could not be sourced.
     */
    public static function missingVerificationKey(string $reason): self
    {
        return new self("Result JWT verification key could not be sourced from the capture context: {$reason}.");
    }

    /**
     * Build the exception for a result JWT whose signature is invalid or untrusted.
     *
     * @param  string  $reason  Short description of the signature failure.
     */
    public static function invalidSignature(string $reason): self
    {
        return new self("Result JWT signature verification failed: {$reason}.");
    }

    /**
     * Build the exception for a result JWT that has expired (or is not yet valid).
     *
     * @param  string  $reason  Short description of the lifetime failure.
     */
    public static function expired(string $reason): self
    {
        return new self("Result JWT has expired or is not yet valid: {$reason}.");
    }

    /**
     * Build the exception for a result JWT issued by an unexpected issuer.
     *
     * @param  string  $expected  Issuer the caller required.
     * @param  string  $actual  Issuer the token carried.
     */
    public static function issuerMismatch(string $expected, string $actual): self
    {
        return new self("Result JWT issuer mismatch: expected '{$expected}', got '{$actual}'.");
    }

    /**
     * Build the exception for a result JWT whose order reference does not match the request.
     *
     * @param  string  $expected  Order reference the caller required.
     * @param  string  $actual  Order reference the token carried.
     */
    public static function orderReferenceMismatch(string $expected, string $actual): self
    {
        return new self("Result JWT order reference mismatch: expected '{$expected}', got '{$actual}'.");
    }

    /**
     * Build the exception for a result JWT whose currency does not match the request.
     *
     * @param  string  $expected  Currency the caller required.
     * @param  string  $actual  Currency the token carried.
     */
    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self("Result JWT currency mismatch: expected '{$expected}', got '{$actual}'.");
    }

    /**
     * Build the exception for a result JWT whose amount does not match the request.
     *
     * @param  string  $expected  Amount the caller required.
     * @param  string  $actual  Amount the token carried.
     */
    public static function amountMismatch(string $expected, string $actual): self
    {
        return new self("Result JWT amount mismatch: expected '{$expected}', got '{$actual}'.");
    }
}
