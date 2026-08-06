<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paylink;

use Hyprpay\Payments\Domain\Exception\GatewayException;

/**
 * The PayLink Payment Integration signing primitive.
 *
 * Byte-compatible with the server's `ExternalPaymentIntegration::buildSignatureString()`
 * and the sibling PayLink SDKs (php/js/python): the signature is
 * `base64(hmac_sha256(implode('', ordered_values), hash_token, true))` — the signed
 * values are concatenated with no separator, in the endpoint's field order, after
 * coercing each to its exact wire string. Values are coerced identically for the
 * request body and the signature so the bytes signed are the bytes sent.
 */
final class PaylinkSignature
{
    /**
     * Compute the base64 HMAC-SHA256 signature over the ordered coerced values.
     *
     * @param  array<int, string>  $orderedValues  Already-coerced signed values in wire order.
     */
    public static function build(array $orderedValues, string $hashToken): string
    {
        return base64_encode(hash_hmac('sha256', implode('', $orderedValues), $hashToken, true));
    }

    /**
     * Constant-time signature comparison (equivalent to PHP's hash_equals).
     */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Coerce a scalar to the exact string the server signs and receives.
     *
     * Mirrors the sibling SDKs: null → '', bool → '1'/'0', int → decimal string,
     * integer-valued float → integer string (no trailing ".0"), other float → its
     * shortest round-trip form, string → itself.
     *
     * @param  mixed  $value
     */
    public static function coerce($value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => self::coerceFloat($value),
            is_string($value) => $value,
            default => throw new GatewayException('Cannot serialize value of type '.gettype($value).' for PayLink signing.'),
        };
    }

    private static function coerceFloat(float $value): string
    {
        if (! is_finite($value)) {
            throw new GatewayException('Cannot serialize non-finite number for PayLink signing.');
        }

        if ($value === floor($value)) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');
    }
}
