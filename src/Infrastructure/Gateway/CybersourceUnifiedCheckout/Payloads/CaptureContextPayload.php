<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Enum\MandateCompletionType;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;

/**
 * Builds the CyberSource Unified Checkout capture-context request body.
 *
 * Used to start a hosted checkout session: the returned JWT context configures the
 * embedded Unified Checkout widget (accepted networks, payment types, capture mandate,
 * and order amount) that collects and tokenizes the payer's card client-side.
 */
final class CaptureContextPayload
{
    /**
     * Build the POST /up/v1/capture-contexts request body.
     *
     * Carries the client version, allowed target origins, allowed card networks and
     * payment types, locale/country, the capture mandate (billing/email/shipping flags),
     * and the order amount and optional billing address. When the request carries a
     * completeMandate, a completeMandate block is added so the widget orchestrates the
     * whole payment client-side (UC v1 autoProcessing) instead of returning a transient
     * token for server-side authorization — including running Decision Manager (device
     * fingerprinting) when decisionManager is enabled, and vaulting the payment credential
     * in TMS (completeMandate.tms.tokenCreate) when createToken is enabled so the result
     * JWT carries reusable token ids for later stored-credential charges.
     *
     * @param  CheckoutSessionRequest  $request  Checkout session inputs (amount, allowed networks/types, origins, locale/country, optional billTo, optional completeMandate, optional TMS token creation).
     * @param  GatewayCredentials  $credentials  Merchant credentials providing default country and locale fallbacks.
     * @return array<string, mixed>
     */
    public static function build(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
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

        $payload = [
            'clientVersion' => $request->clientVersion,
            'targetOrigins' => $request->targetOrigins,
            'allowedCardNetworks' => $request->allowedCardNetworks,
            'allowedPaymentTypes' => $request->allowedPaymentTypes,
            'country' => $request->country ?? $credentials->country,
            'locale' => $request->locale ?? $credentials->locale,
            'captureMandate' => [
                'billingType' => 'FULL',
                'requestEmail' => true,
                'requestPhone' => false,
                'requestShipping' => false,
                'showAcceptedNetworkIcons' => false,
            ],
            'orderInformation' => $orderInformation,
        ];

        if ($request->completeMandate instanceof MandateCompletionType) {
            $completeMandate = [
                'type' => $request->completeMandate->value,
                'decisionManager' => $request->decisionManager,
            ];

            if ($request->createToken) {
                $tms = ['tokenCreate' => true];

                if (filled($request->tokenTypes)) {
                    $tms['tokenTypes'] = $request->tokenTypes;
                }

                $completeMandate['tms'] = $tms;
            }

            $payload['completeMandate'] = $completeMandate;
        }

        return $payload;
    }
}
