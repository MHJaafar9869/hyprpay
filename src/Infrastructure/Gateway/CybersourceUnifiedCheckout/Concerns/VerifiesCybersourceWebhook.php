<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns;

/**
 * Verifies CyberSource webhook notification signatures.
 *
 * CyberSource sends a `v-c-signature: t={ms};keyId={id};sig={base64}` header.
 * The signature is HMAC-SHA256 over "{t}.{rawBody}" keyed by the base64-decoded
 * webhook shared secret. Notifications older than five minutes are rejected.
 */
trait VerifiesCybersourceWebhook
{
    private const MAX_AGE_MS = 300_000;

    /**
     * Validates a CyberSource webhook's `v-c-signature` header against the raw body.
     *
     * Returns false when the shared secret or signature header is missing, the
     * header lacks the `t`/`sig` fields, the notification is older than five
     * minutes, or the recomputed HMAC-SHA256 does not match (constant-time compared).
     *
     * @param  string  $rawBody  Exact raw request body as received (used in the signed string).
     * @param  array<string, string|array<int, string>>  $headers  Inbound request headers.
     * @param  string|null  $sharedSecret  Base64-encoded webhook signing secret.
     */
    protected function webhookSignatureIsValid(string $rawBody, array $headers, ?string $sharedSecret): bool
    {
        if (blank($sharedSecret)) {
            return false;
        }

        $header = $this->headerValue($headers, 'v-c-signature');

        if (blank($header)) {
            return false;
        }

        $parts = $this->parseSignatureHeader($header);

        if (! isset($parts['t'], $parts['sig'])) {
            return false;
        }

        $ageMs = abs($this->currentTimestampMs() - (int) $parts['t']);

        if ($ageMs > self::MAX_AGE_MS) {
            return false;
        }

        $decodedSecret = (string) base64_decode($sharedSecret, true);
        $computed = base64_encode(hash_hmac('sha256', $parts['t'].'.'.$rawBody, $decodedSecret, true));

        return hash_equals($computed, $parts['sig']);
    }

    /**
     * Returns the current time in milliseconds; overridable so tests can freeze
     * the clock when asserting the notification-age window.
     */
    protected function currentTimestampMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Case-insensitively looks up a header value, taking the first entry when the
     * value is an array. Returns null when the header is absent.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === strtolower($name)) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return null;
    }

    /**
     * Parses the `key=value;key=value` signature header into an associative array
     * (e.g. `t`, `keyId`, `sig`), skipping malformed segments.
     *
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $parts = [];

        foreach (explode(';', $header) as $segment) {
            $pair = explode('=', trim($segment), 2);

            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        return $parts;
    }
}
