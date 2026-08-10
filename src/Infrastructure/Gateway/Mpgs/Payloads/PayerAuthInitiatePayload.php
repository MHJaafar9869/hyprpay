<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;

/**
 * Builds the MPGS `INITIATE_AUTHENTICATION` (3-D Secure) request body.
 *
 * Begins payer authentication for the session's card over the browser channel, carrying
 * the order currency and the session that holds the card details.
 */
final class PayerAuthInitiatePayload
{
    /**
     * Build the PUT /order/{orderId}/transaction/{transactionId} INITIATE_AUTHENTICATION body.
     *
     * @param  PayerAuthEnrollRequest  $request  Enrollment inputs (session token, amount).
     * @return array<string, mixed>
     */
    public static function build(PayerAuthEnrollRequest $request): array
    {
        return [
            'apiOperation' => MpgsApiOperation::InitiateAuthentication->value,
            'authentication' => ['channel' => 'PAYER_BROWSER'],
            'order' => ['currency' => $request->money->currency],
            'session' => ['id' => $request->transientToken],
        ];
    }
}
