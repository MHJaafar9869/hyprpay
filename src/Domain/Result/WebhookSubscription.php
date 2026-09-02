<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\WebhookSecurityType;
use Hyprpay\Payments\Domain\Enum\WebhookStatus;
use Hyprpay\Payments\Domain\ValueObject\WebhookProduct;

/**
 * Result DTO describing a webhook subscription — where the gateway delivers notifications, which
 * events it sends, and whether it is currently delivering at all.
 *
 * {@see $status} is the field to watch operationally: the gateway suspends a subscription itself
 * when deliveries keep failing and the retry policy allows it, so a silent integration is often a
 * subscription that quietly went inactive rather than a gateway that stopped sending.
 */
final readonly class WebhookSubscription
{
    /**
     * @param  string|null  $webhookId  Gateway identifier for the subscription
     * @param  string|null  $name  Human-readable name
     * @param  string|null  $description  Human-readable description
     * @param  string|null  $webhookUrl  Endpoint notifications are delivered to
     * @param  string|null  $healthCheckUrl  Endpoint the gateway probes before resuming a suspended subscription
     * @param  WebhookStatus|null  $status  Whether notifications are currently being delivered
     * @param  WebhookSecurityType|null  $securityType  How the gateway authenticates to your endpoint
     * @param  list<WebhookProduct>  $products  Products and event types this subscription receives
     * @param  string|null  $notificationScope  Whether it covers this organization only or its descendants too
     * @param  string|null  $organizationId  Organization the subscription belongs to
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public ?string $webhookId = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $webhookUrl = null,
        public ?string $healthCheckUrl = null,
        public ?WebhookStatus $status = null,
        public ?WebhookSecurityType $securityType = null,
        public array $products = [],
        public ?string $notificationScope = null,
        public ?string $organizationId = null,
        public array $raw = [],
    ) {}

    /**
     * Whether the gateway is currently delivering notifications to this subscription.
     */
    public function isDelivering(): bool
    {
        return $this->status?->isDelivering() === true;
    }

    /**
     * Whether notifications from this subscription carry a signature `verifyWebhook()` can check.
     *
     * False for the oAuth security types, where the gateway authenticates with a bearer token
     * instead of signing the payload — those notifications are authenticated, but not by the SDK.
     */
    public function isSignatureVerifiable(): bool
    {
        return $this->securityType?->isSignatureVerifiable() === true;
    }

    /**
     * Every event type this subscription receives, flattened across its products.
     *
     * @return list<string>
     */
    public function eventTypes(): array
    {
        $events = [];

        foreach ($this->products as $product) {
            foreach ($product->eventTypes as $event) {
                $events[] = $event;
            }
        }

        return array_values(array_unique($events));
    }
}
