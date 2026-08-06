<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Normalized, gateway-agnostic lifecycle status of a payment.
 *
 * Every gateway driver maps its provider-specific transaction states onto these
 * cases so that callers can reason about payment outcomes uniformly, regardless
 * of which underlying gateway produced them.
 */
enum PaymentStatus: string
{
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Pending = 'pending';
    case Declined = 'declined';
    case Voided = 'voided';
    case Reversed = 'reversed';
    case Refunded = 'refunded';
    case Failed = 'failed';

    /**
     * Human-readable display name for the status.
     *
     * @return string The label suitable for showing in UIs and logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::Authorized => 'Authorized',
            self::Captured => 'Captured',
            self::Pending => 'Pending',
            self::Declined => 'Declined',
            self::Voided => 'Voided',
            self::Reversed => 'Reversed',
            self::Refunded => 'Refunded',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the status represents a non-error outcome.
     *
     * Treats authorized, captured, pending, refunded, voided, and reversed as
     * successful, while declined and failed are considered unsuccessful.
     *
     * @return bool True when the payment did not decline or fail.
     */
    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Authorized, self::Captured, self::Pending, self::Refunded, self::Voided, self::Reversed => true,
            self::Declined, self::Failed => false,
        };
    }
}
