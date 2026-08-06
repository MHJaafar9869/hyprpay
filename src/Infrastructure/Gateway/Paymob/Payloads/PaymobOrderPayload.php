<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;

/**
 * Builds the Paymob order-registration request body (POST /ecommerce/orders).
 *
 * The amount is sent in the smallest currency unit (Money's minor amount, i.e.
 * piastres for EGP) with no rounding. The merchant order id is the caller's order
 * reference verbatim (no random suffix), so Paymob deduplicates identical retries.
 */
final class PaymobOrderPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(CheckoutSessionRequest $request): array
    {
        return [
            'delivery_needed' => 'false',
            'amount_cents' => $request->money->minorAmount,
            'currency' => $request->money->currency,
            'merchant_order_id' => $request->orderReference ?? '',
            'items' => [],
        ];
    }
}
