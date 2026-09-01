<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums;

use Hyprpay\Payments\Domain\Enum\SubscriptionStatus;

/**
 * Raw subscription status strings returned by the CyberSource Recurring Billing (RBS) API,
 * with a mapping to the SDK's normalized SubscriptionStatus.
 *
 * These are the states of the subscription itself, read from
 * `subscriptionInformation.status` — not the request status CyberSource returns at the top
 * level of a lifecycle response (COMPLETED/ACCEPTED/DECLINED), which reports only whether
 * the call was accepted.
 */
enum CybersourceSubscriptionStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Delinquent = 'DELINQUENT';
    case Cancelled = 'CANCELLED';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';

    /**
     * Maps this CyberSource subscription status onto the SDK's normalized SubscriptionStatus.
     *
     * The two sets line up one-for-one; the mapping exists so the domain result never carries
     * a driver-specific value.
     */
    public function toSubscriptionStatus(): SubscriptionStatus
    {
        return match ($this) {
            self::Pending => SubscriptionStatus::Pending,
            self::Active => SubscriptionStatus::Active,
            self::Suspended => SubscriptionStatus::Suspended,
            self::Delinquent => SubscriptionStatus::Delinquent,
            self::Cancelled => SubscriptionStatus::Cancelled,
            self::Completed => SubscriptionStatus::Completed,
            self::Failed => SubscriptionStatus::Failed,
        };
    }

    /**
     * Maps a normalized SubscriptionStatus back to the raw CyberSource string.
     *
     * The inverse of {@see toSubscriptionStatus()}, used when a status has to travel *to*
     * CyberSource rather than from it — filtering the subscription list by state.
     */
    public static function fromSubscriptionStatus(SubscriptionStatus $status): self
    {
        return match ($status) {
            SubscriptionStatus::Pending => self::Pending,
            SubscriptionStatus::Active => self::Active,
            SubscriptionStatus::Suspended => self::Suspended,
            SubscriptionStatus::Delinquent => self::Delinquent,
            SubscriptionStatus::Cancelled => self::Cancelled,
            SubscriptionStatus::Completed => self::Completed,
            SubscriptionStatus::Failed => self::Failed,
        };
    }

    /**
     * Resolves a raw status string to a SubscriptionStatus, returning null when the value is
     * null, blank, or not a recognized CyberSource subscription status.
     *
     * Unlike a payment status there is no Failed fallback: a lifecycle response that reports
     * no subscription state at all is not the same as one that reports a failure, so the
     * caller is left to read the request status instead.
     *
     * @param  string|null  $status  Raw `subscriptionInformation.status` from a CyberSource response.
     */
    public static function toSubscriptionStatusOrNull(?string $status): ?SubscriptionStatus
    {
        return self::tryFrom(strtoupper((string) $status))?->toSubscriptionStatus();
    }
}
