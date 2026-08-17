<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Airwallex\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Airwallex PaymentIntent `status` values, mapped to the SDK's normalized status.
 *
 * A PaymentIntent moves through pre-payment states (`REQUIRES_PAYMENT_METHOD`,
 * `REQUIRES_CUSTOMER_ACTION` — e.g. a 3-D Secure challenge) that fold onto
 * {@see PaymentStatus::Pending}; `REQUIRES_CAPTURE` means the funds are authorized and
 * awaiting capture (a manual-capture intent); `SUCCEEDED` means the payment was captured;
 * and `CANCELLED` means the intent was voided. Anything unrecognised falls back to Failed.
 */
enum AirwallexIntentStatus: string
{
    case RequiresPaymentMethod = 'REQUIRES_PAYMENT_METHOD';
    case RequiresCustomerAction = 'REQUIRES_CUSTOMER_ACTION';
    case RequiresCapture = 'REQUIRES_CAPTURE';
    case Succeeded = 'SUCCEEDED';
    case Cancelled = 'CANCELLED';

    /**
     * Map this Airwallex intent status onto the SDK's normalized payment status.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::RequiresPaymentMethod, self::RequiresCustomerAction => PaymentStatus::Pending,
            self::RequiresCapture => PaymentStatus::Authorized,
            self::Succeeded => PaymentStatus::Captured,
            self::Cancelled => PaymentStatus::Voided,
        };
    }

    /**
     * Map a raw intent status string to a normalized status, falling back to Failed.
     *
     * @param  string|null  $status  The raw Airwallex PaymentIntent `status` value.
     */
    public static function toPaymentStatusOrFailed(?string $status): PaymentStatus
    {
        return self::tryFrom((string) $status)?->toPaymentStatus() ?? PaymentStatus::Failed;
    }
}
