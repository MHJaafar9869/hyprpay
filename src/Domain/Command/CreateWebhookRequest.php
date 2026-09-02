<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\WebhookSecurityType;
use Hyprpay\Payments\Domain\ValueObject\WebhookProduct;
use Hyprpay\Payments\Domain\ValueObject\WebhookRetryPolicy;

/**
 * Input DTO for subscribing an endpoint of yours to gateway notifications.
 *
 * This is the other half of `verifyWebhook()`: that verifies what arrives, this decides what is
 * sent and where. Create a webhook security key **first** — the gateway signs each notification
 * with it, and it is the same secret the SDK verifies against, so a subscription created before
 * the key exists has nothing to sign with.
 *
 * {@see $healthCheckUrl} is worth supplying. Without one, a subscription the gateway deactivates
 * after repeated delivery failures has to be reactivated by hand; with one, the gateway probes it
 * and resumes on its own.
 */
final readonly class CreateWebhookRequest
{
    /**
     * Deliver notifications for this organization only.
     */
    public const SCOPE_SELF = 'SELF';

    /**
     * Deliver notifications for this organization and every organization beneath it.
     */
    public const SCOPE_DESCENDANTS = 'DESCENDANTS';

    /**
     * @param  string  $name  Human-readable name for the subscription
     * @param  string  $webhookUrl  Your endpoint that will receive notifications
     * @param  list<WebhookProduct>  $products  Products and event types to subscribe to
     * @param  string|null  $description  Human-readable description
     * @param  string|null  $healthCheckUrl  Endpoint the gateway probes to decide whether to resume a suspended subscription
     * @param  WebhookSecurityType|null  $securityType  How the gateway authenticates to your endpoint; `Key` is the signed-notification scheme `verifyWebhook()` checks
     * @param  array<string, mixed>  $securityConfig  Security settings for the chosen type (e.g. the signature key id)
     * @param  WebhookRetryPolicy|null  $retryPolicy  How failed deliveries are retried; the gateway's defaults apply when omitted
     * @param  string|null  $notificationScope  `SELF` or `DESCENDANTS`; the gateway defaults to DESCENDANTS
     * @param  string|null  $organizationId  Organization the subscription belongs to; defaults to the credentials' organization
     */
    public function __construct(
        public string $name,
        public string $webhookUrl,
        public array $products,
        public ?string $description = null,
        public ?string $healthCheckUrl = null,
        public ?WebhookSecurityType $securityType = null,
        public array $securityConfig = [],
        public ?WebhookRetryPolicy $retryPolicy = null,
        public ?string $notificationScope = null,
        public ?string $organizationId = null,
    ) {}
}
