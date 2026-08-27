<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Installment;

/**
 * Builds the CyberSource payments (authorization/charge) request body for a Unified Checkout charge.
 *
 * Used to authorize or auth-and-capture a payment from the transient token produced by the
 * Unified Checkout widget, optionally attaching 3DS results and device fingerprint data.
 */
final class PaymentPayload
{
    /**
     * Build the POST /pts/v2/payments request body for a transient-token charge.
     *
     * Carries processing information (capture flag, optional commerce indicator), the
     * transient-token card reference, the order amount and optional billing address, and
     * optional client reference code, consumer authentication (3DS) data, and device
     * fingerprint session id.
     *
     * @param  ChargeRequest  $request  Charge inputs (transient token, amount, capture flag, optional commerce indicator, billTo, order reference, consumer authentication, device fingerprint id).
     * @return array<string, mixed>
     */
    public static function build(ChargeRequest $request): array
    {
        $processingInformation = ['capture' => $request->capture];

        if (filled($request->commerceIndicator)) {
            $processingInformation['commerceIndicator'] = $request->commerceIndicator;
        }

        if ($request->installment instanceof Installment) {
            $processingInformation['installment'] = $request->installment->toArray();
        }

        $orderInformation = [
            'amountDetails' => [
                'totalAmount' => $request->money->toDecimalString(),
                'currency' => $request->money->currency,
                ...DccAmountDetails::fields($request->dcc),
            ],
        ];

        $billTo = $request->billTo?->toArray() ?? [];

        if (filled($billTo)) {
            $orderInformation['billTo'] = $billTo;
        }

        $payload = [
            'processingInformation' => $processingInformation,
            'tokenInformation' => ['transientTokenJwt' => $request->transientToken],
            'orderInformation' => $orderInformation,
        ];

        if (filled($request->orderReference)) {
            $payload['clientReferenceInformation'] = ['code' => ClientReference::code($request->orderReference)];
        }

        if (filled($request->consumerAuthentication)) {
            $payload['consumerAuthenticationInformation'] = $request->consumerAuthentication;
        }

        $deviceInformation = DeviceInformation::fields($request->deviceFingerprintId, $request->useRawFingerprintSessionId);

        if (filled($deviceInformation)) {
            $payload['deviceInformation'] = $deviceInformation;
        }

        return $payload;
    }
}
