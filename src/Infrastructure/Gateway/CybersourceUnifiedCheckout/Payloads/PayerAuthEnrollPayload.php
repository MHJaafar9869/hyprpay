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
     * under consumerAuthenticationInformation. The deviceInformation block merges the
     * optional fraud device fingerprint with the collected browser device data; when browser
     * data is present the device channel is marked as Browser so the issuer risk-assesses it.
     *
     * @param  PayerAuthEnrollRequest  $request  Enrollment inputs (transient token, amount, optional billTo, returnUrl, referenceId, order reference, device fingerprint id, browser device data).
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

        $browserDeviceInformation = BrowserDeviceInformation::fields($request->device);

        if (filled($browserDeviceInformation)) {
            $consumerAuthenticationInformation['deviceChannel'] = 'Browser';
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

        $deviceInformation = array_merge(
            DeviceInformation::fields($request->deviceFingerprintId, $request->useRawFingerprintSessionId),
            $browserDeviceInformation,
        );

        if (filled($deviceInformation)) {
            $payload['deviceInformation'] = $deviceInformation;
        }

        return $payload;
    }
}
