<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;

/**
 * Builds the CyberSource Payer Authentication enrollment (3DS) request body.
 *
 * Used to run the 3-D Secure enrollment/setup check for a tokenized card before
 * authorization, returning device-data-collection or challenge details.
 */
final class PayerAuthEnrollPayload
{
    /**
     * Build the POST /risk/v1/authentications request body (3DS enrollment check).
     *
     * Carries the client reference code, the transient-token card reference, the order
     * amount and optional billing address, plus optional return URL and reference id
     * under consumerAuthenticationInformation and an optional device fingerprint session id.
     *
     * @param  PayerAuthEnrollRequest  $request  Enrollment inputs (transient token, amount, optional billTo, returnUrl, referenceId, order reference, device fingerprint id).
     * @return array<string, mixed>
     */
    public static function build(PayerAuthEnrollRequest $request): array
    {
        $orderInformation = [
            'amountDetails' => [
                'totalAmount' => $request->money->toDecimalString(),
                'currency' => $request->money->currency,
            ],
        ];

        $billTo = $request->billTo?->toArray() ?? [];

        if (filled($billTo)) {
            $orderInformation['billTo'] = $billTo;
        }

        $consumerAuthenticationInformation = [];

        if (filled($request->returnUrl)) {
            $consumerAuthenticationInformation['returnUrl'] = $request->returnUrl;
        }

        if (filled($request->referenceId)) {
            $consumerAuthenticationInformation['referenceId'] = $request->referenceId;
        }

        $payload = [
            'clientReferenceInformation' => [
                'code' => ClientReference::code($request->orderReference),
            ],
            'tokenInformation' => ['transientTokenJwt' => $request->transientToken],
            'orderInformation' => $orderInformation,
        ];

        if (filled($consumerAuthenticationInformation)) {
            $payload['consumerAuthenticationInformation'] = $consumerAuthenticationInformation;
        }

        $deviceInformation = DeviceInformation::fields($request->deviceFingerprintId, $request->useRawFingerprintSessionId);

        if (filled($deviceInformation)) {
            $payload['deviceInformation'] = $deviceInformation;
        }

        return $payload;
    }
}
