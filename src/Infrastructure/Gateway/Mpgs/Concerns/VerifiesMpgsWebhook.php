<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Concerns;

use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Verifies inbound MPGS webhook (notification) authenticity.
 *
 * MPGS webhook notifications are authenticated by a shared secret the merchant configures
 * in the gateway: the notification carries it in the `X-Notification-Secret` header, which
 * is compared against the configured secret in constant time. (This is MPGS's notification
 * secret scheme, not an HMAC signature.)
 */
trait VerifiesMpgsWebhook
{
    /**
     * Determine whether the notification's secret header matches the configured secret.
     *
     * @param  array<string, string|array<int, string>>  $headers  Inbound webhook headers.
     * @param  string|null  $secret  The configured notification secret, or null when unset.
     */
    private function webhookSecretIsValid(array $headers, ?string $secret): bool
    {
        if (blank($secret)) {
            return false;
        }

        $provided = $this->webhookHeader($headers, 'x-notification-secret');

        return $provided !== null && hash_equals($secret, $provided);
    }

    /**
     * Look up a header value by name, case-insensitively, returning the first value when
     * the header is multi-valued.
     *
     * @param  array<string, string|array<int, string>>  $headers  Inbound webhook headers.
     * @param  string  $name  Lower-cased header name to match.
     */
    private function webhookHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return is_array($value) ? Value::nullableString($value[0] ?? null) : Value::nullableString($value);
            }
        }

        return null;
    }
}
