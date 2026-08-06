<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\RefundRequest;

/**
 * Builds the CyberSource refund request body.
 *
 * Used to refund (fully or partially) a previously captured payment, referencing the
 * original transaction by its id in the endpoint path.
 */
final class RefundPayload
{
    /**
     * Build the POST /pts/v2/payments/{id}/refunds request body.
     *
     * Carries the client reference code (with an optional reason as comments) and the
     * order amount to refund.
     *
     * @param  RefundRequest  $request  Refund inputs (order reference, original transaction id, amount, optional reason).
     * @return array<string, mixed>
     */
    public static function build(RefundRequest $request): array
    {
        $clientReferenceInformation = [
            'code' => ClientReference::code($request->orderReference, $request->transactionId),
        ];

        if (filled($request->reason)) {
            $clientReferenceInformation['comments'] = $request->reason;
        }

        return [
            'clientReferenceInformation' => $clientReferenceInformation,
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $request->money->toDecimalString(),
                    'currency' => $request->money->currency,
                ],
            ],
        ];
    }
}
