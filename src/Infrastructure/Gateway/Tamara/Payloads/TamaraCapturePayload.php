<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Tamara\Payloads;

use Hyprpay\Payments\Domain\Command\CaptureRequest;

/**
 * Builds the POST /payments/capture request body that settles an authorised Tamara order.
 *
 * References the order by id and sends the captured total, with the shipping, tax, and
 * discount components defaulted to zero in the captured amount's currency.
 */
final class TamaraCapturePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(CaptureRequest $request): array
    {
        return [
            'order_id' => $request->transactionId,
            'total_amount' => TamaraMoney::of($request->money),
            'shipping_amount' => TamaraMoney::zero($request->money),
            'tax_amount' => TamaraMoney::zero($request->money),
            'discount_amount' => TamaraMoney::zero($request->money),
        ];
    }
}
