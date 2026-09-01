<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for amending an existing recurring subscription in place.
 *
 * A partial update: only the fields supplied are sent, and everything left null keeps its
 * current value. What can change is narrower than what {@see CreateSubscriptionRequest} sets —
 * the billing cadence and the currency are fixed once the subscription exists, so there is no
 * {@see BillingPeriod} and no currency here. Re-pricing takes the new amount in the
 * subscription's existing currency; switching cadence means cancelling and re-creating.
 *
 * The vault customer cannot be re-pointed either: to move a subscription onto a different card,
 * update the payment instrument behind the existing customer token in the vault.
 */
final readonly class UpdateSubscriptionRequest
{
    /**
     * @param  string  $subscriptionId  Identifier of the subscription to amend
     * @param  string|null  $name  New human-readable subscription name
     * @param  string|null  $planId  Move the subscription onto a different billing plan
     * @param  string|null  $code  New merchant-assigned subscription code
     * @param  string|null  $startDate  Reschedule the first charge, UTC — `YYYY-MM-DD` or a full `YYYY-MM-DDThh:mm:ssZ`; only meaningful while the subscription has not started
     * @param  int|null  $billingCycles  New total number of cycles to bill before the subscription completes
     * @param  Money|null  $billingAmount  New amount charged each cycle; its currency is ignored, since a subscription bills in the currency it was created with
     * @param  Money|null  $setupFee  New one-off setup fee; its currency is ignored for the same reason
     * @param  string|null  $orderReference  Merchant order/reference number for reconciliation
     * @param  string|null  $idempotencyKey  Optional idempotency key so a retried update is not applied twice. Defaults to the order reference when omitted.
     */
    public function __construct(
        public string $subscriptionId,
        public ?string $name = null,
        public ?string $planId = null,
        public ?string $code = null,
        public ?string $startDate = null,
        public ?int $billingCycles = null,
        public ?Money $billingAmount = null,
        public ?Money $setupFee = null,
        public ?string $orderReference = null,
        public ?string $idempotencyKey = null,
    ) {}
}
