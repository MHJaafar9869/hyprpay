<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;

/**
 * Builds the CyberSource Payer Authentication results (3DS) request body.
 *
 * Used after the 3-D Secure challenge/frictionless flow to validate the authentication
 * and retrieve the resulting CAVV/ECI so the subsequent payment can be authorized.
 */
final class PayerAuthValidatePayload
{
    /**
     * Build the POST /risk/v1/authentication-results request body (3DS validation).
     *
     * Carries the client reference code, the order amount, the authentication transaction id
     * to validate, and optionally the transient-token card reference.
     *
     * @param  ValidatePayerAuthRequest  $request  Validation inputs (authentication transaction id, amount, optional transient token, order reference).
     * @return array<string, mixed>
     */
    public static function build(ValidatePayerAuthRequest $request): array
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => ClientReference::code($request->orderReference, $request->authenticationTransactionId),
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $request->money->toDecimalString(),
                    'currency' => $request->money->currency,
                ],
            ],
            'consumerAuthenticationInformation' => [
                'authenticationTransactionId' => $request->authenticationTransactionId,
            ],
        ];

        if (filled($request->transientToken)) {
            $payload['tokenInformation'] = ['transientTokenJwt' => $request->transientToken];
        }

        return $payload;
    }
}
