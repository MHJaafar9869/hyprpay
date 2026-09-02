<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Command\CreateWebhookRequest;
use Hyprpay\Payments\Domain\ValueObject\WebhookProduct;

/**
 * One product an account may subscribe to, with the events available under it.
 *
 * The catalogue is entitlement-scoped and its events are nested per product, which is why neither
 * the product ids nor the event names are modelled as enums in this SDK: an account without Fraud
 * Management should not see its events, and an event valid under one product is meaningless under
 * another. Discover them here and feed them into {@see CreateWebhookRequest::$products}.
 */
final readonly class WebhookProductCatalog
{
    /**
     * @param  string|null  $productId  Product identifier, as passed in a subscription
     * @param  string|null  $productName  Human-readable product name
     * @param  list<WebhookEventType>  $eventTypes  Events available under this product
     * @param  array<string, mixed>  $raw  Raw gateway payload for the product
     */
    public function __construct(
        public ?string $productId = null,
        public ?string $productName = null,
        public array $eventTypes = [],
        public array $raw = [],
    ) {}

    /**
     * Every event name under this product, ready to pass to a subscription.
     *
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_values(array_filter(array_map(
            static fn (WebhookEventType $event): ?string => $event->eventName,
            $this->eventTypes,
        )));
    }

    /**
     * This product as a subscription entry, covering every event it offers.
     *
     * @param  list<string>|null  $only  Restrict to these event names; null subscribes to all of them.
     */
    public function toSubscription(?array $only = null): WebhookProduct
    {
        $events = $only === null
            ? $this->eventNames()
            : array_values(array_intersect($this->eventNames(), $only));

        return new WebhookProduct((string) $this->productId, $events);
    }

    /**
     * The events that lose their value if a retry queue delays them.
     *
     * @return list<WebhookEventType>
     */
    public function timeSensitiveEvents(): array
    {
        return array_values(array_filter(
            $this->eventTypes,
            static fn (WebhookEventType $event): bool => $event->isTimeSensitive,
        ));
    }

    /**
     * The events whose payload arrives encrypted, so message-level encryption must be configured
     * before they can be read.
     *
     * @return list<WebhookEventType>
     */
    public function encryptedEvents(): array
    {
        return array_values(array_filter(
            $this->eventTypes,
            static fn (WebhookEventType $event): bool => $event->isEncrypted,
        ));
    }
}
