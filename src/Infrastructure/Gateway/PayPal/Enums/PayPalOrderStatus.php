<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * PayPal Orders v2 order-level `status` values, mapped to the SDK's normalized status.
 *
 * Carried on the order returned by create/show-order and by the capture/authorize-order
 * calls. An order stays in a pre-payment state (`CREATED`, `SAVED`, `APPROVED`,
 * `PAYER_ACTION_REQUIRED`) until the merchant captures or authorizes it, so those all
 * fold onto {@see PaymentStatus::Pending}; `COMPLETED` means the payment was processed
 * and `VOIDED` that the order was cancelled. The order status is coarse — the precise
 * outcome (captured vs authorized, declined) lives on the nested payment resource, which
 * {@see PayPalPaymentStatus} maps; use this only when reading the order itself.
 */
enum PayPalOrderStatus: string
{
    case Created = 'CREATED';
    case Saved = 'SAVED';
    case Approved = 'APPROVED';
    case PayerActionRequired = 'PAYER_ACTION_REQUIRED';
    case Completed = 'COMPLETED';
    case Voided = 'VOIDED';

    /**
     * Map this PayPal order status onto the SDK's normalized payment status.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Created, self::Saved, self::Approved, self::PayerActionRequired => PaymentStatus::Pending,
            self::Completed => PaymentStatus::Captured,
            self::Voided => PaymentStatus::Voided,
        };
    }

    /**
     * Map a raw order status string to a normalized status, falling back to Failed.
     *
     * @param  string|null  $status  The raw PayPal order `status` value.
     */
    public static function toPaymentStatusOrFailed(?string $status): PaymentStatus
    {
        return self::tryFrom((string) $status)?->toPaymentStatus() ?? PaymentStatus::Failed;
    }
}
