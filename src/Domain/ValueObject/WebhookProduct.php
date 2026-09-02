<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

use Hyprpay\Payments\Domain\Command\CreateWebhookRequest;

/**
 * One product a webhook subscription listens to, and which of its events it wants.
 *
 * A subscription can span several products, each with its own event list. Which product ids and
 * event types an account may subscribe to depends on its entitlements, so discover them with the
 * gateway's product catalogue rather than hard-coding them — see
 * {@see CreateWebhookRequest::$products}.
 */
final readonly class WebhookProduct
{
    /**
     * @param  string  $productId  Gateway product identifier (e.g. `payments`)
     * @param  array<int, string>  $eventTypes  Event types within that product to receive
     */
    public function __construct(
        public string $productId,
        public array $eventTypes = [],
    ) {}

    /**
     * The product as the subscription payload carries it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'productId' => $this->productId,
            'eventTypes' => array_values($this->eventTypes),
        ], static fn (array|string $value): bool => $value !== [] && $value !== '');
    }
}
