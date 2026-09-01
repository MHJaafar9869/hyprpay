<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Lifecycle status of a recurring billing plan.
 *
 * A plan is the reusable template a subscription is created from — its cadence, cycle count,
 * and price. Only an active plan can back a new subscription: a draft has never been published
 * and an inactive one has been withdrawn. Existing subscriptions already running on a plan are
 * unaffected by it being deactivated.
 */
enum PlanStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';

    /**
     * Human-readable display name for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    /**
     * Whether new subscriptions can be created against this plan.
     */
    public function isSubscribable(): bool
    {
        return $this === self::Active;
    }
}
