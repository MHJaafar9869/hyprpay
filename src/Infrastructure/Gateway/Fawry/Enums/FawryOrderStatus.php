<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * FawryPay order/payment status values, mapped to the SDK's normalized status.
 *
 * Returned by the Get Payment Status V2 API and carried on Server Notification V2
 * webhooks. `toPaymentStatus()` folds these onto the gateway-agnostic
 * {@see PaymentStatus} so callers reason about outcomes uniformly.
 */
enum FawryOrderStatus: string
{
    case New = 'NEW';
    case Paid = 'PAID';
    case Unpaid = 'UNPAID';
    case Canceled = 'CANCELED';
    case Refunded = 'REFUNDED';
    case PartialRefunded = 'PARTIAL_REFUNDED';
    case Expired = 'EXPIRED';
    case Failed = 'FAILED';

    /**
     * Map this FawryPay status onto the SDK's normalized payment status.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Paid => PaymentStatus::Captured,
            self::New, self::Unpaid => PaymentStatus::Pending,
            self::Refunded, self::PartialRefunded => PaymentStatus::Refunded,
            self::Canceled => PaymentStatus::Voided,
            self::Expired, self::Failed => PaymentStatus::Failed,
        };
    }

    /**
     * Map a raw status string to a normalized status, falling back to Failed.
     *
     * @param  string|null  $status  The raw FawryPay order/payment status value.
     */
    public static function toPaymentStatusOrFailed(?string $status): PaymentStatus
    {
        return self::tryFrom((string) $status)?->toPaymentStatus() ?? PaymentStatus::Failed;
    }
}
