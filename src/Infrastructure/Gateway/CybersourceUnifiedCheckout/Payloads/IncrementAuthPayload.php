<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\IncrementAuthorizationRequest;

/**
 * Builds the CyberSource incremental-authorization request body.
 *
 * The amount sent is the *additional* amount, not the new total — CyberSource adds it to the hold
 * already placed by the original authorization.
 */
final class IncrementAuthPayload
{
    /**
     * Build the PATCH /pts/v2/payments/{id} request body.
     *
     * @param  IncrementAuthorizationRequest  $request  The authorization to raise and by how much.
     * @return array<string, mixed>
     */
    public static function build(IncrementAuthorizationRequest $request): array
    {
        $payload = [
            'clientReferenceInformation' => ClientReference::block($request->orderReference, null, $request->transactionId),
            'orderInformation' => [
                'amountDetails' => [
                    'additionalAmount' => $request->additionalAmount->toDecimalString(),
                    'currency' => $request->additionalAmount->currency,
                ],
            ],
        ];

        if (filled($request->reason)) {
            $payload['processingInformation'] = ['authorizationOptions' => ['initiator' => ['merchantInitiatedTransaction' => ['reason' => $request->reason]]]];
        }

        return $payload;
    }
}
