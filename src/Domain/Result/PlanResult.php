<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\PlanStatus;
use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Result DTO describing a recurring billing plan after a create, lookup, or lifecycle call.
 *
 * The same shape reports the plan whether it was just created, fetched, amended, activated, or
 * deactivated. As with {@see SubscriptionResult}, {@see $status} is the plan's own lifecycle
 * state while {@see $requestStatus} is the gateway's verdict on the call that produced it.
 */
final readonly class PlanResult
{
    /**
     * @param  bool  $success  Whether the plan operation succeeded
     * @param  PlanStatus|null  $status  Normalised lifecycle status of the plan
     * @param  string|null  $planId  Gateway identifier for the plan, referenced when creating subscriptions
     * @param  string|null  $code  Plan code — merchant-assigned or gateway-generated
     * @param  string|null  $name  Plan name
     * @param  string|null  $description  Plan description
     * @param  BillingPeriod|null  $billingPeriod  Cadence subscriptions on this plan charge at
     * @param  int|null  $billingCycles  Total cycles a subscription on this plan bills
     * @param  Money|null  $billingAmount  Amount charged each cycle
     * @param  Money|null  $setupFee  One-off fee charged on the first cycle
     * @param  string|null  $requestStatus  Raw gateway status for the request itself (e.g. COMPLETED, ACCEPTED)
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public bool $success,
        public ?PlanStatus $status = null,
        public ?string $planId = null,
        public ?string $code = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?BillingPeriod $billingPeriod = null,
        public ?int $billingCycles = null,
        public ?Money $billingAmount = null,
        public ?Money $setupFee = null,
        public ?string $requestStatus = null,
        public array $raw = [],
    ) {}

    /**
     * Whether a new subscription can be created against this plan right now.
     */
    public function isSubscribable(): bool
    {
        return $this->status?->isSubscribable() === true;
    }
}
