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
     * Build the whole `clientReferenceInformation` block: the correlation code, plus the merchant
     * transaction id when one was supplied.
     *
     * That transaction id is a precondition, not a nicety. CyberSource's timeout void and timeout
     * reversal — the only way to cancel a payment, capture, refund, or credit whose reply never
     * arrived — match on `clientReferenceInformation.transactionId` from the *original* request. A
     * call sent without one cannot be reversed that way afterwards, so send it on anything you may
     * later need to undo blind.
     *
     * @param  string|null  $reference  Preferred merchant order reference.
     * @param  string|null  $merchantTransactionId  Unique per-request id, for a later timeout void or reversal.
     * @param  string  $fallback  Value used when the reference is blank (typically the transaction/instrument id).
     * @return array<string, string>
     */
    public static function block(?string $reference, ?string $merchantTransactionId = null, string $fallback = ''): array
    {
        return array_filter([
            'code' => self::code($reference, $fallback),
            'transactionId' => $merchantTransactionId,
        ], filled(...));
    }

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
