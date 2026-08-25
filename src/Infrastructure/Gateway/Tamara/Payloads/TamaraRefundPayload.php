<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Tamara\Payloads;

use Hyprpay\Payments\Domain\Command\RefundRequest;

/**
 * Builds the POST /payments/simplified-refund/{order_id} request body.
 *
 * Sends the refund total and, when the caller supplied one, the refund reason as Tamara's comment.
 */
final class TamaraRefundPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(RefundRequest $request): array
    {
        $body = ['total_amount' => TamaraMoney::of($request->money)];

        if (filled($request->reason)) {
            $body['comment'] = $request->reason;
        }

        return $body;
    }
}
