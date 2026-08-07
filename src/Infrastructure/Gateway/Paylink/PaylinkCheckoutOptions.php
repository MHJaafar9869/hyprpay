<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paylink;

use Hyprpay\Payments\Domain\Command\CheckoutOptions;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Typed checkout options for the PayLink gateway.
 *
 * Replaces PayLink's options bag with named fields: the server-to-server webhook URL, an
 * order-details note, the payment mode, whether to return an iframe-ready checkout URL,
 * and an explicit idempotency key (which otherwise defaults to the order reference).
 */
final readonly class PaylinkCheckoutOptions implements CheckoutOptions
{
    /**
     * @param  string|null  $webhookUrl  Server-to-server webhook URL for payment notifications.
     * @param  string|null  $orderDetails  Free-text order details shown on the hosted checkout.
     * @param  string|null  $paymentMode  PayLink payment mode selector.
     * @param  bool  $iframe  Request an iframe-embeddable checkout URL instead of a redirect.
     * @param  string|null  $idempotencyKey  Explicit idempotency key (defaults to the order reference).
     */
    public function __construct(
        public ?string $webhookUrl = null,
        public ?string $orderDetails = null,
        public ?string $paymentMode = null,
        public bool $iframe = false,
        public ?string $idempotencyKey = null,
    ) {}

    /**
     * Resolve a request's options to PaylinkCheckoutOptions (typed object or legacy array).
     */
    public static function fromRequest(CheckoutSessionRequest $request): self
    {
        return $request->options instanceof self ? $request->options : self::fromArray($request->optionsArray());
    }

    /**
     * Build the options from a raw PayLink option-key array (backward compatibility).
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            webhookUrl: Value::nullableString($options['webhook_url'] ?? null),
            orderDetails: Value::nullableString($options['order_details'] ?? null),
            paymentMode: Value::nullableString($options['payment_mode'] ?? null),
            iframe: filter_var($options['iframe'] ?? false, FILTER_VALIDATE_BOOLEAN),
            idempotencyKey: Value::nullableString($options['idempotency_key'] ?? null),
        );
    }

    /**
     * Render the options as PayLink's raw option-key array, dropping unset fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'webhook_url' => $this->webhookUrl,
            'order_details' => $this->orderDetails,
            'payment_mode' => $this->paymentMode,
            'iframe' => $this->iframe ?: null,
            'idempotency_key' => $this->idempotencyKey,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
