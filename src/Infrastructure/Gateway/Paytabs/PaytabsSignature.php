<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs;

/**
 * The PayTabs IPN/return callback signing primitive.
 *
 * Byte-compatible with PayTabs' documented `is_valid_redirect` verification: the
 * signature is `hmac_sha256(http_build_query(ksort(array_filter(fields))), server_key)`
 * in lowercase hex — every posted field except `signature`, with empty values dropped,
 * sorted by key, rendered as a URL-encoded query string, then HMAC-SHA256 signed with
 * the merchant's server key. PayTabs signs the flat form fields (`respStatus`,
 * `tranRef`, `cartId`, …), so the driver verifies over exactly those fields.
 */
final class PaytabsSignature
{
    /**
     * Recompute the expected signature over the callback fields (excluding `signature`).
     *
     * @param  array<string, mixed>  $fields  The posted callback fields.
     * @param  string  $serverKey  The merchant server key PayTabs signed with.
     */
    public static function expected(array $fields, string $serverKey): string
    {
        unset($fields['signature']);
        $filtered = array_filter($fields);
        ksort($filtered);

        return hash_hmac('sha256', http_build_query($filtered), $serverKey);
    }

    /**
     * Constant-time-verify a callback's `signature` field against the recomputed value.
     *
     * @param  array<string, mixed>  $fields  The posted callback fields, including `signature`.
     * @param  string  $serverKey  The merchant server key PayTabs signed with.
     */
    public static function verify(array $fields, string $serverKey): bool
    {
        $provided = $fields['signature'] ?? null;

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals(self::expected($fields, $serverKey), $provided);
    }
}
