<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;

/**
 * Builds the MPGS `PAY`/`AUTHORIZE` transaction request body for a session charge.
 *
 * The charge is session-based: the request's transient token is the MPGS session id that
 * carries the card details captured client-side, and the payload adds the authoritative
 * order amount plus any billing address. PAY authorises and captures; AUTHORIZE holds
 * funds for a later capture, selected by the request's capture flag.
 */
final class ChargePayload
{
    /**
     * Build the PUT /order/{orderId}/transaction/{transactionId} charge request body.
     *
     * @param  ChargeRequest  $request  Charge inputs (session token, amount, capture flag, billing).
     * @return array<string, mixed>
     */
    public static function build(ChargeRequest $request): array
    {
        return array_filter([
            'apiOperation' => ($request->capture ? MpgsApiOperation::Pay : MpgsApiOperation::Authorize)->value,
            'order' => MpgsPayloadParts::order($request->money),
            'session' => ['id' => $request->transientToken],
            'billing' => MpgsPayloadParts::billing($request->billTo),
        ], static fn (mixed $value): bool => $value !== [] && $value !== null);
    }
}
