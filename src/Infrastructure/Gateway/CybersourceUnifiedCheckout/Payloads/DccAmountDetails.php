<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Produces the Dynamic Currency Conversion fields added to a CyberSource amountDetails block.
 *
 * When a {@see DccQuote} is attached to a charge/capture/refund, the amount is expressed
 * in the cardholder's billing currency (the base totalAmount/currency), and these extra
 * fields carry the original merchant amount, the quoted exchange rate, and the
 * currency-conversion id so CyberSource applies the exact rate the cardholder was quoted.
 */
final class DccAmountDetails
{
    /**
     * The DCC additions for an amountDetails block, or an empty array when there is no quote.
     *
     * @param  DccQuote|null  $dcc  The quote to apply, or null for a standard (non-DCC) transaction.
     * @return array<string, mixed>
     */
    public static function fields(?DccQuote $dcc): array
    {
        if (! $dcc instanceof DccQuote || ! $dcc->originalAmount instanceof Money) {
            return [];
        }

        $fields = [
            'originalAmount' => $dcc->originalAmount->toDecimalString(),
            'originalCurrency' => $dcc->originalAmount->currency,
            'currencyConversion' => array_filter([
                'indicator' => '1',
                'id' => $dcc->id,
                'reconciliationId' => $dcc->id,
            ], static fn (?string $value): bool => $value !== null),
        ];

        if ($dcc->exchangeRate !== null) {
            $fields['exchangeRate'] = $dcc->exchangeRate;
        }

        if ($dcc->exchangeRateTimeStamp !== null) {
            $fields['exchangeRateTimeStamp'] = $dcc->exchangeRateTimeStamp;
        }

        return $fields;
    }
}
