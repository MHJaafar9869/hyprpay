<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;

/**
 * Builds the MPGS `AUTHENTICATE_PAYER` (3-D Secure) request body.
 *
 * Completes payer authentication on the same order/transaction the enrollment started,
 * carrying the order amount, the session holding the card details when supplied, and
 * optional 3-D Secure browser device data for risk-based authentication.
 */
final class PayerAuthenticatePayload
{
    /**
     * Build the PUT /order/{orderId}/transaction/{transactionId} AUTHENTICATE_PAYER body.
     *
     * @param  ValidatePayerAuthRequest  $request  Validation inputs (authentication transaction id, amount, optional session token, optional browser device data).
     * @return array<string, mixed>
     */
    public static function build(ValidatePayerAuthRequest $request): array
    {
        $device = MpgsPayloadParts::device($request->device);

        return array_filter([
            'apiOperation' => MpgsApiOperation::AuthenticatePayer->value,
            'order' => MpgsPayloadParts::order($request->money),
            'session' => $request->transientToken !== null ? ['id' => $request->transientToken] : null,
            'device' => $device === [] ? null : $device,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
