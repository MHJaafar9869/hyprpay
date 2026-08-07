<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Result\DccQuote;

/**
 * Builds the CyberSource authorization reversal request body.
 *
 * Used to reverse (release) an authorization that has not yet been captured, referencing
 * the original transaction by its id in the endpoint path.
 */
final class ReversalPayload
{
    /**
     * Build the POST /pts/v2/payments/{id}/reversals request body.
     *
     * Carries the client reference code and the reversal information (amount to release and
     * a reason, defaulting to "canceled order").
     *
     * @param  ReversalRequest  $request  Reversal inputs (order reference, original transaction id, amount, optional reason).
     * @return array<string, mixed>
     */
    public static function build(ReversalRequest $request): array
    {
        $amountDetails = ['totalAmount' => $request->money->toDecimalString()];

        if ($request->dcc instanceof DccQuote) {
            $amountDetails['currency'] = $request->money->currency;
        }

        return [
            'clientReferenceInformation' => [
                'code' => ClientReference::code($request->orderReference, $request->transactionId),
            ],
            'reversalInformation' => [
                'amountDetails' => $amountDetails,
                'reason' => $request->reason ?? 'canceled order',
            ],
        ];
    }
}
