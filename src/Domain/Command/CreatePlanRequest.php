<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\PlanStatus;
use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for creating a recurring billing plan.
 *
 * A plan is the reusable template a subscription is created from: the cadence, how many cycles
 * to bill, and the price. Creating one is what lets {@see CreateSubscriptionRequest::$planId}
 * reference shared pricing instead of restating the cadence and amount on every subscription —
 * and a subscription can still override the amount for per-customer pricing on a shared plan.
 *
 * A plan created as {@see PlanStatus::Draft} is not subscribable until it is activated.
 */
final readonly class CreatePlanRequest
{
    /**
     * @param  string  $name  Plan name shown in the gateway's back office
     * @param  BillingPeriod  $billingPeriod  How often a subscription on this plan charges
     * @param  Money|null  $billingAmount  Amount charged each cycle
     * @param  Money|null  $setupFee  One-off fee charged on the first cycle, in the same currency
     * @param  int|null  $billingCycles  Total cycles to bill before a subscription completes; null bills until cancelled
     * @param  string|null  $description  Human-readable description of the plan
     * @param  string|null  $code  Merchant-assigned plan code; the gateway generates one when omitted
     * @param  PlanStatus|null  $status  Status to create the plan in — Draft to stage it, Active to publish; the gateway defaults to Active
     */
    public function __construct(
        public string $name,
        public BillingPeriod $billingPeriod,
        public ?Money $billingAmount = null,
        public ?Money $setupFee = null,
        public ?int $billingCycles = null,
        public ?string $description = null,
        public ?string $code = null,
        public ?PlanStatus $status = null,
    ) {}
}
