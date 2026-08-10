<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;

/**
 * Builds the MPGS `PAY` transaction request body for a stored-credential (card-on-file) charge.
 *
 * Charges a previously vaulted token, flagging the credential-on-file state
 * (`TO_BE_STORED` on the first charge that establishes the relationship, `STORED`
 * thereafter) and, for merchant-initiated repeat charges, attaching the stored-credential
 * `agreement` MPGS requires.
 */
final class StoredCredentialPayload
{
    /**
     * Build the PUT /order/{orderId}/transaction/{transactionId} stored-credential charge body.
     *
     * @param  StoredCredentialChargeRequest  $request  Stored-credential charge inputs (token, amount, initiator).
     * @return array<string, mixed>
     */
    public static function build(StoredCredentialChargeRequest $request): array
    {
        $payload = [
            'apiOperation' => MpgsApiOperation::Pay->value,
            'order' => MpgsPayloadParts::order($request->money),
            'sourceOfFunds' => [
                'type' => 'CARD',
                'token' => $request->paymentInstrumentId,
                'provided' => [
                    'card' => ['storedOnFile' => $request->isFirstCharge ? 'TO_BE_STORED' : 'STORED'],
                ],
            ],
            'transaction' => ['source' => 'INTERNET'],
        ];

        if ($request->initiator->isMerchantInitiated() && ! $request->isFirstCharge) {
            $payload['agreement'] = [
                'id' => $request->customerId ?? $request->paymentInstrumentId,
                'type' => 'OTHER',
            ];
        }

        return $payload;
    }
}
