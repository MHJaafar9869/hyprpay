<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;

/**
 * Builds the CyberSource payments request body for a stored-credential charge.
 *
 * Used to charge a saved TMS payment instrument as a merchant-initiated (MIT) or
 * customer-initiated (CIT) transaction, flagging first vs subsequent use of the
 * stored credential per CyberSource requirements.
 */
final class StoredCredentialPayload
{
    /**
     * Build the POST /pts/v2/payments request body for a stored-credential (MIT/CIT) charge.
     *
     * Carries the client reference code, processing information (capture, internet commerce
     * indicator, and initiator/stored-credential options — adding merchantInitiatedTransaction
     * for MIT), the saved payment instrument (and optional customer) reference, the order amount,
     * and an optional device fingerprint session id for fraud screening.
     *
     * @param  StoredCredentialChargeRequest  $request  Stored-credential inputs (initiator type, first-charge flag, payment instrument id, optional customer id, amount, order reference, device fingerprint id).
     * @return array<string, mixed>
     */
    public static function build(StoredCredentialChargeRequest $request): array
    {
        $initiator = [
            'type' => $request->initiator->value,
            'storedCredentialUsed' => ! $request->isFirstCharge,
        ];

        if ($request->initiator->isMerchantInitiated()) {
            $initiator['merchantInitiatedTransaction'] = ['reason' => '1'];
        }

        $paymentInformation = [
            'paymentInstrument' => ['id' => $request->paymentInstrumentId],
        ];

        if (filled($request->customerId)) {
            $paymentInformation['customer'] = ['id' => $request->customerId];
        }

        $payload = [
            'clientReferenceInformation' => [
                'code' => ClientReference::code($request->orderReference, $request->paymentInstrumentId),
            ],
            'processingInformation' => [
                'capture' => true,
                'commerceIndicator' => 'internet',
                'authorizationOptions' => ['initiator' => $initiator],
            ],
            'paymentInformation' => $paymentInformation,
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => $request->money->toDecimalString(),
                    'currency' => $request->money->currency,
                ],
            ],
        ];

        $deviceInformation = DeviceInformation::fields($request->deviceFingerprintId, $request->useRawFingerprintSessionId);

        if (filled($deviceInformation)) {
            $payload['deviceInformation'] = $deviceInformation;
        }

        return $payload;
    }
}
