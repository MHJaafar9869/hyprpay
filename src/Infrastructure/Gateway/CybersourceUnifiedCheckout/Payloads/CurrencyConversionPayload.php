<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\DccRateRequest;

/**
 * Builds the CyberSource DCC rate-inquiry (currency conversion) request body.
 *
 * Used to quote a Dynamic Currency Conversion rate: it sends the original amount in the
 * merchant's currency and the card number whose BIN determines the cardholder's currency,
 * to POST /vas/v1/currencyconversion.
 */
final class CurrencyConversionPayload
{
    /**
     * Build the POST /vas/v1/currencyconversion request body.
     *
     * @param  DccRateRequest  $request  Rate-inquiry inputs (original amount + merchant currency, card number, optional reference).
     * @return array<string, mixed>
     */
    public static function build(DccRateRequest $request): array
    {
        $payload = [
            'orderInformation' => [
                'amountDetails' => [
                    'originalAmount' => $request->money->toDecimalString(),
                    'originalCurrency' => $request->money->currency,
                ],
            ],
            'paymentInformation' => [
                'card' => ['number' => $request->cardNumber],
            ],
        ];

        if (filled($request->orderReference)) {
            $payload['clientReferenceInformation'] = ['code' => ClientReference::code($request->orderReference)];
        }

        return $payload;
    }
}
