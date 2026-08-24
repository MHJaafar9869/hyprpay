<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Tamara\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Tamara's order/operation lifecycle statuses and their mapping to the SDK's canonical status.
 *
 * Covers the order states reported by the checkout and order-detail APIs plus the
 * terminal statuses returned by the capture, refund, and cancel operations, so the
 * driver can normalise any Tamara response onto {@see PaymentStatus}.
 */
enum TamaraOrderStatus: string
{
    case New = 'new';
    case Approved = 'approved';
    case Authorised = 'authorised';
    case Declined = 'declined';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case Updated = 'updated';
    case FullyCaptured = 'fully_captured';
    case PartiallyCaptured = 'partially_captured';
    case FullyRefunded = 'fully_refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * Map this Tamara status onto the SDK's normalized payment status.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::New, self::Approved => PaymentStatus::Pending,
            self::Authorised => PaymentStatus::Authorized,
            self::FullyCaptured, self::PartiallyCaptured => PaymentStatus::Captured,
            self::FullyRefunded, self::PartiallyRefunded => PaymentStatus::Refunded,
            self::Canceled, self::Updated => PaymentStatus::Voided,
            self::Declined => PaymentStatus::Declined,
            self::Expired => PaymentStatus::Failed,
        };
    }

    /**
     * Map a raw Tamara status string to a normalized status, falling back to Failed.
     *
     * @param  string|null  $status  The raw Tamara status value, or null when absent.
     */
    public static function toPaymentStatusOrFailed(?string $status): PaymentStatus
    {
        return self::tryFrom((string) $status)?->toPaymentStatus() ?? PaymentStatus::Failed;
    }
}
