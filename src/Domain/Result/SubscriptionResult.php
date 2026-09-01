<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\SubscriptionStatus;

/**
 * Result DTO describing a recurring subscription after a create, lookup, or lifecycle call.
 *
 * Returned by every subscription operation, so the same shape reports the subscription's
 * standing whether it was just opened, fetched, cancelled, suspended, or reactivated.
 * {@see $status} is the subscription's own lifecycle state; {@see $requestStatus} is the
 * gateway's verdict on the call that produced it (a create can be accepted while the
 * subscription itself is still pending its first charge).
 */
final readonly class SubscriptionResult
{
    /**
     * @param  bool  $success  Whether the subscription operation succeeded
     * @param  SubscriptionStatus|null  $status  Normalised lifecycle status of the subscription, when the gateway reported one
     * @param  string|null  $subscriptionId  Gateway identifier for the subscription, used by the lifecycle operations
     * @param  string|null  $subscriptionCode  Subscription code — merchant-assigned or gateway-generated
     * @param  string|null  $planId  Identifier of the billing plan the subscription follows, when it references one
     * @param  string|null  $name  Human-readable subscription name
     * @param  string|null  $startDate  Date of the first charge as the gateway records it (UTC `YYYY-MM-DDThh:mm:ssZ`)
     * @param  string|null  $orderReference  Merchant order/reference number carried on the subscription
     * @param  string|null  $requestStatus  Raw gateway status for the request itself (e.g. COMPLETED, ACCEPTED, DECLINED)
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public bool $success,
        public ?SubscriptionStatus $status = null,
        public ?string $subscriptionId = null,
        public ?string $subscriptionCode = null,
        public ?string $planId = null,
        public ?string $name = null,
        public ?string $startDate = null,
        public ?string $orderReference = null,
        public ?string $requestStatus = null,
        public array $raw = [],
    ) {}
}
