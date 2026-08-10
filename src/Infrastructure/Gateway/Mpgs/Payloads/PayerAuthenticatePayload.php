<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;

/**
 * Builds the MPGS `AUTHENTICATE_PAYER` (3-D Secure) request body.
 *
 * Completes payer authentication on the same order/transaction the enrollment started,
 * carrying the order amount and, when supplied, the session holding the card details.
 */
final class PayerAuthenticatePayload
{
    /**
     * Build the PUT /order/{orderId}/transaction/{transactionId} AUTHENTICATE_PAYER body.
     *
     * @param  ValidatePayerAuthRequest  $request  Validation inputs (authentication transaction id, amount, optional session token).
     * @return array<string, mixed>
     */
    public static function build(ValidatePayerAuthRequest $request): array
    {
        return array_filter([
            'apiOperation' => MpgsApiOperation::AuthenticatePayer->value,
            'order' => MpgsPayloadParts::order($request->money),
            'session' => $request->transientToken !== null ? ['id' => $request->transientToken] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
