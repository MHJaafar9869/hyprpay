<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;

/**
 * Builds the MPGS `REFUND` transaction request body.
 *
 * Returns funds against the order's captured payment; MPGS refunds at the order level, so
 * the payload carries only the amount to refund (the order is identified by the URL).
 */
final class RefundPayload
{
    /**
     * Build the PUT /order/{orderId}/transaction/{transactionId} refund request body.
     *
     * @param  RefundRequest  $request  Refund inputs (amount to return).
     * @return array<string, mixed>
     */
    public static function build(RefundRequest $request): array
    {
        return [
            'apiOperation' => MpgsApiOperation::Refund->value,
            'transaction' => MpgsPayloadParts::transactionAmount($request->money),
        ];
    }
}
