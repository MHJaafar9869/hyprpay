<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

/**
 * Produces the CyberSource deviceInformation block carrying the Decision Manager device fingerprint.
 *
 * The fingerprint session id is the <session ID> portion only — the same value passed to the
 * browser profiling tag as session_id=<merchant ID><session ID>, without the merchant id prefix.
 * When useRawFingerprintSessionId is true, CyberSource looks up the device using the
 * fingerprintSessionId exactly as sent instead of prefixing it with the merchant id.
 */
final class DeviceInformation
{
    /**
     * The deviceInformation block, or an empty array when no fingerprint session id is set.
     *
     * @param  string|null  $fingerprintSessionId  Decision Manager device fingerprint session id (the <session ID> part), or null to omit the block.
     * @param  bool  $useRawFingerprintSessionId  Whether CyberSource should use the raw session id (true) instead of the default merchant-prefixed lookup (false).
     * @return array<string, mixed>
     */
    public static function fields(?string $fingerprintSessionId, bool $useRawFingerprintSessionId = false): array
    {
        if (! filled($fingerprintSessionId)) {
            return [];
        }

        $fields = ['fingerprintSessionId' => $fingerprintSessionId];

        if ($useRawFingerprintSessionId) {
            $fields['useRawFingerprintSessionId'] = true;
        }

        return $fields;
    }
}
