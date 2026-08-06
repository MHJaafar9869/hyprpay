<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns;

use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Decodes CyberSource Unified Checkout transient-token and capture-context JWTs.
 *
 * The tokens are unsigned-payload JWTs; only their claims are read (never trusted
 * for authorization). Wallet detection is a marker heuristic over the decoded claims.
 */
trait ParsesTransientToken
{
    /**
     * Base64url-decodes the JWT payload segment and returns its claims as an array.
     *
     * Returns null when the token has fewer than two segments, the payload is not
     * valid base64url, or the decoded JSON is not an object.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeJwtClaims(string $token): ?array
    {
        $segments = explode('.', $token);

        if (count($segments) < 2) {
            return null;
        }

        $decoded = base64_decode(strtr($segments[1], '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        $claims = json_decode($decoded, true);

        return is_array($claims) ? Value::array($claims) : null;
    }

    /**
     * Returns the transient token's `jti` claim (its unique identifier), or null
     * when absent or not a string.
     */
    protected function transientTokenJti(string $token): ?string
    {
        $claims = $this->decodeJwtClaims($token);
        $jti = $claims['jti'] ?? null;

        return is_string($jti) ? $jti : null;
    }

    /**
     * Returns the token's payment `content` claim (falling back to `data`), which
     * holds the captured payment details, or null when neither is present.
     *
     * @return array<string, mixed>|null
     */
    protected function transientTokenContent(string $token): ?array
    {
        $claims = $this->decodeJwtClaims($token);
        $content = $claims['content'] ?? $claims['data'] ?? null;

        return is_array($content) ? Value::array($content) : null;
    }

    /**
     * Detects whether the token's claims mention an Apple Pay marker.
     */
    protected function transientTokenIsApplePay(string $token): bool
    {
        return $this->tokenContainsMarker($token, ['APPLE_PAY', 'applepay', 'apple pay']);
    }

    /**
     * Detects whether the token's claims mention a Google Pay marker.
     */
    protected function transientTokenIsGooglePay(string $token): bool
    {
        return $this->tokenContainsMarker($token, ['GOOGLE_PAY', 'googlepay', 'google pay']);
    }

    /**
     * Detects whether the token represents a wallet payment (Apple Pay or Google Pay).
     */
    protected function transientTokenIsWallet(string $token): bool
    {
        if ($this->transientTokenIsApplePay($token)) {
            return true;
        }

        return (bool) $this->transientTokenIsGooglePay($token);
    }

    /**
     * Case-insensitively searches the JSON-encoded claims for any of the given markers.
     *
     * @param  array<int, string>  $markers  Candidate substrings that identify the wallet.
     */
    private function tokenContainsMarker(string $token, array $markers): bool
    {
        $claims = $this->decodeJwtClaims($token);

        if ($claims === null) {
            return false;
        }

        $haystack = strtolower((string) json_encode($claims));

        foreach ($markers as $marker) {
            if (str_contains($haystack, strtolower($marker))) {
                return true;
            }
        }

        return false;
    }
}
