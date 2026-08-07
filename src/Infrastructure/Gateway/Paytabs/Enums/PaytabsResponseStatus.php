<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * PayTabs transaction `response_status` codes, mapped to the SDK's normalized status.
 *
 * Carried on the `payment_result` object of every PT2 transaction response and on the
 * `respStatus` field of the IPN/return callback. `toPaymentStatus()` folds these onto
 * the gateway-agnostic {@see PaymentStatus}. Note PayTabs overloads Authorised (`A`):
 * a `sale` that returns `A` is captured, while an `auth` hold returns Hold (`H`), so
 * `A` maps to Captured and `H` to Authorized.
 */
enum PaytabsResponseStatus: string
{
    case Authorised = 'A';
    case Hold = 'H';
    case Pending = 'P';
    case Voided = 'V';
    case Declined = 'D';
    case Error = 'E';
    case Expired = 'X';
    case Cancelled = 'C';

    /**
     * Map this PayTabs status onto the SDK's normalized payment status.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Authorised => PaymentStatus::Captured,
            self::Hold => PaymentStatus::Authorized,
            self::Pending => PaymentStatus::Pending,
            self::Voided, self::Cancelled => PaymentStatus::Voided,
            self::Declined => PaymentStatus::Declined,
            self::Error, self::Expired => PaymentStatus::Failed,
        };
    }

    /**
     * Map a raw status string to a normalized status, falling back to Failed.
     *
     * @param  string|null  $status  The raw PayTabs response_status value.
     */
    public static function toPaymentStatusOrFailed(?string $status): PaymentStatus
    {
        return self::tryFrom((string) $status)?->toPaymentStatus() ?? PaymentStatus::Failed;
    }

    /**
     * Whether the status is an approval (Authorised or Hold).
     *
     * @param  string|null  $status  The raw PayTabs response_status value.
     */
    public static function isApproved(?string $status): bool
    {
        return in_array(self::tryFrom((string) $status), [self::Authorised, self::Hold], true);
    }
}
