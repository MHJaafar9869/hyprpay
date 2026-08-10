<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * The order-level `status` values MPGS reports, mapped to the SDK's PaymentStatus.
 *
 * Used when mapping a retrieved order (transaction snapshot) or an order webhook into a
 * normalized status, since those carry an aggregate order status rather than a single
 * transaction `result`.
 */
enum MpgsOrderStatus: string
{
    case Authorized = 'AUTHORIZED';
    case Captured = 'CAPTURED';
    case PartiallyCaptured = 'PARTIALLY_CAPTURED';
    case Refunded = 'REFUNDED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case ExcessivelyRefunded = 'EXCESSIVELY_REFUNDED';
    case Voided = 'VOIDED';
    case Cancelled = 'CANCELLED';
    case Disbursed = 'DISBURSED';
    case Pending = 'PENDING';
    case Verified = 'VERIFIED';
    case Failed = 'FAILED';
    case Declined = 'DECLINED';
    case ChargebackProcessed = 'CHARGEBACK_PROCESSED';

    /**
     * Map this order status onto the SDK's normalized PaymentStatus.
     *
     * Capture/refund/disbursement outcomes collapse to their nearest normalized status;
     * authorized and pending/verified map to Authorized/Pending; cancelled/voided to
     * Voided; declined and failed to their respective failure states.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Authorized => PaymentStatus::Authorized,
            self::Captured, self::PartiallyCaptured, self::Disbursed => PaymentStatus::Captured,
            self::Refunded, self::PartiallyRefunded, self::ExcessivelyRefunded => PaymentStatus::Refunded,
            self::Voided, self::Cancelled => PaymentStatus::Voided,
            self::Pending, self::Verified => PaymentStatus::Pending,
            self::Declined => PaymentStatus::Declined,
            self::Failed, self::ChargebackProcessed => PaymentStatus::Failed,
        };
    }

    /**
     * Resolve a raw order-status string to a PaymentStatus, defaulting to Failed when the
     * value is null or not a recognized MPGS order status.
     *
     * @param  string|null  $status  Raw order `status` string from an MPGS response or webhook.
     */
    public static function toPaymentStatusOrFailed(?string $status): PaymentStatus
    {
        return self::tryFrom((string) $status)?->toPaymentStatus() ?? PaymentStatus::Failed;
    }
}
