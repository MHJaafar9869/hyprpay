<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for amending an existing recurring billing plan.
 *
 * A partial update: only the fields supplied are sent and everything left null keeps its value.
 * Unlike a subscription — whose cadence is fixed once it exists — a plan's billing period *can*
 * be changed here, because a plan is a template rather than a live billing agreement.
 *
 * Changing a plan does not retroactively re-price subscriptions already running on it; it
 * governs subscriptions created from the plan afterwards.
 */
final readonly class UpdatePlanRequest
{
    /**
     * @param  string  $planId  Identifier of the plan to amend
     * @param  string|null  $name  New plan name
     * @param  string|null  $description  New plan description
     * @param  BillingPeriod|null  $billingPeriod  New cadence for subscriptions created from this plan
     * @param  int|null  $billingCycles  New total cycle count
     * @param  Money|null  $billingAmount  New per-cycle amount
     * @param  Money|null  $setupFee  New one-off setup fee
     */
    public function __construct(
        public string $planId,
        public ?string $name = null,
        public ?string $description = null,
        public ?BillingPeriod $billingPeriod = null,
        public ?int $billingCycles = null,
        public ?Money $billingAmount = null,
        public ?Money $setupFee = null,
    ) {}
}
