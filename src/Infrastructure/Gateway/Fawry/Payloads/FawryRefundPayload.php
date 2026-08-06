<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads;

use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawrySignature;

/**
 * Builds the FawryPay refund request body.
 *
 * Targets POST /ECommerceWeb/Fawry/payments/refund. The refund references the original
 * FawryPay reference number (carried as the request's transaction id), the refund amount,
 * and an optional reason; the signature covers those fields plus the secure key.
 */
final class FawryRefundPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(RefundRequest $request, GatewayCredentials $credentials): array
    {
        $amount = $request->money->toDecimalString();

        $body = [
            'merchantCode' => $credentials->merchantId,
            'referenceNumber' => $request->transactionId,
            'refundAmount' => $amount,
        ];

        if (filled($request->reason)) {
            $body['reason'] = $request->reason;
        }

        $body['signature'] = FawrySignature::refund(
            $credentials->merchantId,
            $request->transactionId,
            $amount,
            $request->reason,
            $credentials->sharedSecret,
        );

        return $body;
    }
}
