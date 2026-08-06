<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob;

use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Computes the Paymob transaction-callback HMAC.
 *
 * Paymob signs its transaction webhooks by concatenating a fixed, lexicographically
 * ordered set of fields from the transaction object and hashing the result with
 * HMAC-SHA512 keyed by the merchant's HMAC secret. Booleans are rendered as the
 * literal strings "true"/"false" and missing values contribute an empty string,
 * matching Paymob's own calculation. The field order below is taken verbatim from
 * the Paymob documentation and must not be reordered.
 */
final class PaymobHmac
{
    /**
     * @var array<int, string>
     */
    private const FIELDS = [
        'amount_cents',
        'created_at',
        'currency',
        'error_occured',
        'has_parent_transaction',
        'id',
        'integration_id',
        'is_3d_secure',
        'is_auth',
        'is_capture',
        'is_refunded',
        'is_standalone_payment',
        'is_voided',
        'order.id',
        'owner',
        'pending',
        'source_data.pan',
        'source_data.sub_type',
        'source_data.type',
        'success',
    ];

    /**
     * Compute the expected HMAC for a Paymob transaction object.
     *
     * @param  array<string, mixed>  $transaction  The Paymob transaction object (the callback `obj`).
     * @param  string  $secret  The merchant's HMAC secret.
     * @return string The lowercase hex HMAC-SHA512 digest.
     */
    public static function forTransaction(array $transaction, string $secret): string
    {
        $concatenated = '';

        foreach (self::FIELDS as $field) {
            $concatenated .= self::stringify(data_get($transaction, $field));
        }

        return hash_hmac('sha512', $concatenated, $secret);
    }

    /**
     * Render a value the way Paymob does when building the HMAC string.
     *
     * @param  mixed  $value
     */
    private static function stringify($value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            default => Value::string($value),
        };
    }
}
