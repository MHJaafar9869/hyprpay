<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;

/**
 * Builds the MPGS `CAPTURE` transaction request body.
 *
 * Settles funds against the order's prior authorization; MPGS captures at the order level,
 * so the payload carries only the amount to capture (the order is identified by the URL).
 */
final class CapturePayload
{
    /**
     * Build the PUT /order/{orderId}/transaction/{transactionId} capture request body.
     *
     * @param  CaptureRequest  $request  Capture inputs (amount to settle).
     * @return array<string, mixed>
     */
    public static function build(CaptureRequest $request): array
    {
        return [
            'apiOperation' => MpgsApiOperation::Capture->value,
            'transaction' => MpgsPayloadParts::transactionAmount($request->money),
        ];
    }
}
