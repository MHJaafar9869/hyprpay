<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\DccRateRequest;

/**
 * Builds the MPGS `paymentOptionsInquiry` request body for a Dynamic Currency Conversion rate quote.
 *
 * Unlike the transaction operations, a payment-options inquiry is a POST to the dedicated
 * `/paymentOptionsInquiry` resource (no `apiOperation`, no order/transaction id). The DCC variant
 * carries the order amount in the merchant's base currency and identifies the cardholder's currency
 * from the card's BIN — MPGS accepts a card `prefix` (the leading digits) rather than the full PAN —
 * and MPGS returns the converted amount and rate in a `currencyConversion` block.
 */
final class PaymentOptionsInquiryPayload
{
    /**
     * Number of leading card digits sent as the BIN prefix MPGS resolves the payer currency from.
     */
    private const PREFIX_LENGTH = 9;

    /**
     * Build the POST /paymentOptionsInquiry body for a DCC rate quote.
     *
     * @param  DccRateRequest  $request  DCC inputs (original amount and merchant currency, card number).
     * @return array<string, mixed>
     */
    public static function dccRate(DccRateRequest $request): array
    {
        return [
            'order' => MpgsPayloadParts::order($request->money),
            'sourceOfFunds' => ['provided' => ['card' => ['prefix' => self::prefix($request->cardNumber)]]],
        ];
    }

    /**
     * Reduce a card number to the leading-digit BIN prefix MPGS resolves the payer currency from.
     */
    private static function prefix(string $cardNumber): string
    {
        $digits = (string) preg_replace('/\D/', '', $cardNumber);

        return substr($digits, 0, self::PREFIX_LENGTH);
    }
}
