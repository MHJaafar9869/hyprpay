<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\CaptureRequest;

/**
 * Builds the CyberSource capture request body.
 *
 * Used to capture (settle) funds for a previously authorized payment, referencing
 * the original authorization by its transaction id in the endpoint path.
 */
final class CapturePayload
{
    /**
     * Build the POST /pts/v2/payments/{id}/captures request body.
     *
     * Carries the client reference correlation code and the order amount to settle.
     *
     * @param  CaptureRequest  $request  Capture inputs (order reference, original transaction id, amount to capture).
     * @return array<string, mixed>
     */
    public static function build(CaptureRequest $request): array
    {
        return [
            'clientReferenceInformation' => [
                'code' => ClientReference::code($request->orderReference, $request->transactionId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $request->money->toDecimalString(),
                    'currency' => $request->money->currency,
                    ...DccAmountDetails::fields($request->dcc),
                ],
            ],
        ];
    }
}
