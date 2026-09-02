<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\CreditRequest;

/**
 * Builds the CyberSource standalone-credit request body.
 *
 * Unlike a refund there is no transaction to reference, so the card has to be named outright —
 * by transient token, vault payment instrument, or vault customer. The merchant transaction id is
 * carried when supplied so a credit whose reply is lost can still be reversed with a timeout void;
 * for a payment that pushes money out, that matters more than it does for one that takes it in.
 */
final class CreditPayload
{
    /**
     * Build the POST /pts/v2/credits request body.
     *
     * @param  CreditRequest  $request  The card to credit and by how much.
     * @return array<string, mixed>
     */
    public static function build(CreditRequest $request): array
    {
        $payload = [
            'clientReferenceInformation' => ClientReference::block($request->orderReference, $request->merchantTransactionId),
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $request->money->toDecimalString(),
                    'currency' => $request->money->currency,
                ],
            ],
        ];

        $billTo = $request->billTo?->toArray() ?? [];

        if (filled($billTo)) {
            $payload['orderInformation']['billTo'] = $billTo;
        }

        if (filled($request->transientToken)) {
            $payload['tokenInformation'] = ['transientTokenJwt' => $request->transientToken];
        }

        $paymentInformation = array_filter([
            'paymentInstrument' => filled($request->paymentInstrumentId) ? ['id' => $request->paymentInstrumentId] : null,
            'customer' => filled($request->customerId) ? ['id' => $request->customerId] : null,
        ], static fn (?array $value): bool => $value !== null);

        if (filled($paymentInformation)) {
            $payload['paymentInformation'] = $paymentInformation;
        }

        return $payload;
    }
}
