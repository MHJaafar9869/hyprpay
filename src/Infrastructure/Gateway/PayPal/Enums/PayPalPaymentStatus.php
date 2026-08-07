<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * PayPal Payments v2 resource `status` values, mapped to the SDK's normalized status.
 *
 * Carried on the capture, authorization, and refund objects PayPal returns (nested in an
 * order's `purchase_units[].payments`, or fetched directly). Unlike the order status,
 * this distinguishes the real outcome: a held authorization (`CREATED`) is
 * {@see PaymentStatus::Authorized}, a settled capture (`COMPLETED`/`CAPTURED`) is
 * {@see PaymentStatus::Captured}, and a refund's `COMPLETED` is
 * {@see PaymentStatus::Refunded} — the caller passes {@see toPaymentStatus()} the
 * expected terminal status so a completed refund is not misread as a capture.
 */
enum PayPalPaymentStatus: string
{
    case Created = 'CREATED';
    case Captured = 'CAPTURED';
    case Completed = 'COMPLETED';
    case Pending = 'PENDING';
    case PartiallyCaptured = 'PARTIALLY_CAPTURED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Refunded = 'REFUNDED';
    case Declined = 'DECLINED';
    case Denied = 'DENIED';
    case Voided = 'VOIDED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case Failed = 'FAILED';

    /**
     * Map this PayPal resource status onto the SDK's normalized payment status.
     *
     * `COMPLETED` is context-sensitive — it means captured on a capture but refunded on
     * a refund — so `$completedAs` supplies the intended terminal status for it.
     *
     * @param  PaymentStatus  $completedAs  The status a `COMPLETED` resource resolves to.
     */
    public function toPaymentStatus(PaymentStatus $completedAs = PaymentStatus::Captured): PaymentStatus
    {
        return match ($this) {
            self::Created => PaymentStatus::Authorized,
            self::Captured, self::PartiallyCaptured => PaymentStatus::Captured,
            self::Completed => $completedAs,
            self::Pending => PaymentStatus::Pending,
            self::Refunded, self::PartiallyRefunded => PaymentStatus::Refunded,
            self::Declined, self::Denied => PaymentStatus::Declined,
            self::Voided, self::Cancelled => PaymentStatus::Voided,
            self::Expired, self::Failed => PaymentStatus::Failed,
        };
    }

    /**
     * Map a raw resource status string to a normalized status, falling back to Failed.
     *
     * @param  string|null  $status  The raw PayPal resource `status` value.
     * @param  PaymentStatus  $completedAs  The status a `COMPLETED` resource resolves to.
     */
    public static function toPaymentStatusOrFailed(?string $status, PaymentStatus $completedAs = PaymentStatus::Captured): PaymentStatus
    {
        return self::tryFrom((string) $status)?->toPaymentStatus($completedAs) ?? PaymentStatus::Failed;
    }
}
