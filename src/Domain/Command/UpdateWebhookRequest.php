<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\WebhookProduct;
use Hyprpay\Payments\Domain\ValueObject\WebhookRetryPolicy;

/**
 * Input DTO for amending an existing webhook subscription.
 *
 * A partial update: only the fields supplied are sent and everything left null keeps its value.
 * Changing the delivery state is a separate call — use the gateway's activate/deactivate
 * operation rather than trying to set a status here.
 */
final readonly class UpdateWebhookRequest
{
    /**
     * @param  string  $webhookId  Identifier of the subscription to amend
     * @param  string|null  $name  New human-readable name
     * @param  string|null  $description  New description
     * @param  string|null  $webhookUrl  New endpoint to deliver to
     * @param  string|null  $healthCheckUrl  New health-check endpoint
     * @param  list<WebhookProduct>|null  $products  Replacement product/event subscriptions
     * @param  WebhookRetryPolicy|null  $retryPolicy  Replacement retry policy
     * @param  string|null  $notificationScope  `SELF` or `DESCENDANTS`
     */
    public function __construct(
        public string $webhookId,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $webhookUrl = null,
        public ?string $healthCheckUrl = null,
        public ?array $products = null,
        public ?WebhookRetryPolicy $retryPolicy = null,
        public ?string $notificationScope = null,
    ) {}
}
