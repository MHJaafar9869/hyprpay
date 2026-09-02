<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\CreateWebhookRequest;
use Hyprpay\Payments\Domain\Command\UpdateWebhookRequest;
use Hyprpay\Payments\Domain\Enum\WebhookSecurityType;
use Hyprpay\Payments\Domain\Enum\WebhookStatus;
use Hyprpay\Payments\Domain\ValueObject\WebhookProduct;

/**
 * Builds the CyberSource notification-subscription request bodies.
 *
 * Creating a subscription states the whole thing; updating states only what changes, so the two
 * builders are deliberately separate rather than one with everything optional. The security
 * policy is emitted only when a type is chosen, letting the gateway apply its own default.
 */
final class WebhookPayload
{
    /**
     * Build the POST /notification-subscriptions/v2/webhooks request body.
     *
     * @param  CreateWebhookRequest  $request  The subscription to create.
     * @param  string  $organizationId  Organization the subscription belongs to.
     * @return array<string, mixed>
     */
    public static function create(CreateWebhookRequest $request, string $organizationId): array
    {
        $securityPolicy = [];

        if ($request->securityType instanceof WebhookSecurityType) {
            $securityPolicy = array_filter([
                'securityType' => $request->securityType->value,
                'config' => $request->securityConfig,
            ], static fn (array|string $value): bool => $value !== [] && $value !== '');
        }

        return array_filter([
            'name' => $request->name,
            'description' => $request->description,
            'organizationId' => $organizationId,
            'webhookUrl' => $request->webhookUrl,
            'healthCheckUrl' => $request->healthCheckUrl,
            'notificationScope' => $request->notificationScope,
            'products' => self::products($request->products),
            'retryPolicy' => $request->retryPolicy?->toArray(),
            'securityPolicy' => $securityPolicy,
        ], static fn (array|string|null $value): bool => ! in_array($value, [null, [], ''], true));
    }

    /**
     * Build the PATCH /notification-subscriptions/v2/webhooks/{id} request body for a partial amend.
     *
     * @param  UpdateWebhookRequest  $request  The fields to change.
     * @return array<string, mixed>
     */
    public static function update(UpdateWebhookRequest $request): array
    {
        return array_filter([
            'name' => $request->name,
            'description' => $request->description,
            'webhookUrl' => $request->webhookUrl,
            'healthCheckUrl' => $request->healthCheckUrl,
            'notificationScope' => $request->notificationScope,
            'products' => $request->products === null ? null : self::products($request->products),
            'retryPolicy' => $request->retryPolicy?->toArray(),
        ], static fn (array|string|null $value): bool => ! in_array($value, [null, [], ''], true));
    }

    /**
     * Build the PUT /notification-subscriptions/v2/webhooks/{id}/status request body.
     *
     * @param  WebhookStatus  $status  The delivery state to move the subscription to.
     * @return array<string, mixed>
     */
    public static function status(WebhookStatus $status): array
    {
        return ['status' => $status->value];
    }

    /**
     * Build the POST /kms/egress/v2/keys-sym request body for a webhook signing key.
     *
     * @param  string  $action  Whether to have the gateway generate the key, store one you supply, or refresh it.
     * @param  array<string, mixed>  $keyInformation  Key attributes (provider, tenant, key type) as the gateway's webhook guide specifies.
     * @return array<string, mixed>
     */
    public static function securityKey(string $action, array $keyInformation): array
    {
        return array_filter([
            'clientRequestAction' => $action,
            'keyInformation' => $keyInformation,
        ], static fn (array|string $value): bool => $value !== [] && $value !== '');
    }

    /**
     * Render the product/event subscriptions as the payload carries them.
     *
     * @param  list<WebhookProduct>  $products
     * @return list<array<string, mixed>>
     */
    private static function products(array $products): array
    {
        return array_values(array_map(
            static fn (WebhookProduct $product): array => $product->toArray(),
            $products,
        ));
    }
}
