<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

/**
 * Derives the CyberSource clientReferenceInformation.code correlation value.
 *
 * Shared helper used by the payload builders to produce the merchant reference code
 * that ties an outbound CyberSource request back to an order/transaction, applying the
 * CyberSource field length limit.
 */
final class ClientReference
{
    private const MAX_LENGTH = 50;

    /**
     * Resolve the clientReferenceInformation.code, truncated to the CyberSource 50-character limit.
     *
     * Returns the provided reference when present, otherwise the fallback value.
     *
     * @param  string|null  $reference  Preferred merchant order reference.
     * @param  string  $fallback  Value used when the reference is blank (typically the transaction/instrument id).
     */
    public static function code(?string $reference, string $fallback = ''): string
    {
        $value = filled($reference) ? $reference : $fallback;

        return substr($value, 0, self::MAX_LENGTH);
    }
}
