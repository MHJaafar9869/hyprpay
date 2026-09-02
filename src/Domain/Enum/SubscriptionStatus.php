<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Normalized, gateway-agnostic lifecycle status of a recurring subscription.
 *
 * Gateway drivers map their provider-specific subscription states onto these cases
 * so callers can reason about a subscription's standing uniformly. Distinct from
 * {@see PaymentStatus}, which describes a single charge: a subscription outlives the
 * individual payments it schedules.
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Delinquent = 'delinquent';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Human-readable display name for the status.
     *
     * @return string The label suitable for showing in UIs and logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Delinquent => 'Delinquent',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the subscription is still on its billing schedule.
     *
     * True only for an active subscription — a pending one has not started billing yet,
     * and suspended, delinquent, cancelled, completed, and failed subscriptions are not
     * currently charging the cardholder.
     */
    public function isBilling(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the subscription has reached an end state it cannot bill from again.
     *
     * Cancelled, completed, and failed subscriptions are terminal; a suspended or
     * delinquent one can still be brought back with an activate call, and a pending
     * one has yet to start.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Completed, self::Failed => true,
            self::Pending, self::Active, self::Suspended, self::Delinquent => false,
        };
    }
}
