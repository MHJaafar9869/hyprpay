<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs;

use Hyprpay\Payments\Domain\Command\CheckoutOptions;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Typed checkout options for the MPGS Hosted Checkout session.
 *
 * Carries the MPGS-specific interaction fields the hosted checkout needs: the payment
 * operation to perform (PURCHASE authorises and captures, AUTHORIZE authorises only,
 * VERIFY validates the card), the merchant display name/URL shown on the payment page,
 * the return URL the customer is sent back to, and the checkout mode.
 */
final readonly class MpgsCheckoutOptions implements CheckoutOptions
{
    /**
     * @param  string|null  $operation  Interaction operation: PURCHASE, AUTHORIZE, VERIFY, or NONE (defaults to PURCHASE).
     * @param  string|null  $merchantName  Merchant display name shown on the hosted payment page.
     * @param  string|null  $merchantUrl  Merchant website URL shown on the hosted payment page.
     * @param  string|null  $returnUrl  URL the customer returns to after completing checkout.
     * @param  string|null  $checkoutMode  Checkout mode: WEBSITE or PAYMENT_LINK (defaults to WEBSITE).
     */
    public function __construct(
        public ?string $operation = null,
        public ?string $merchantName = null,
        public ?string $merchantUrl = null,
        public ?string $returnUrl = null,
        public ?string $checkoutMode = null,
    ) {}

    /**
     * Resolve a request's options to MpgsCheckoutOptions (typed object or legacy array).
     */
    public static function fromRequest(CheckoutSessionRequest $request): self
    {
        return $request->options instanceof self ? $request->options : self::fromArray($request->optionsArray());
    }

    /**
     * Build the options from a raw MPGS option-key array.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            operation: Value::nullableString($options['operation'] ?? null),
            merchantName: Value::nullableString($options['merchant_name'] ?? null),
            merchantUrl: Value::nullableString($options['merchant_url'] ?? null),
            returnUrl: Value::nullableString($options['return_url'] ?? null),
            checkoutMode: Value::nullableString($options['checkout_mode'] ?? null),
        );
    }

    /**
     * Render the options as MPGS's raw option-key array, dropping unset fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'operation' => $this->operation,
            'merchant_name' => $this->merchantName,
            'merchant_url' => $this->merchantUrl,
            'return_url' => $this->returnUrl,
            'checkout_mode' => $this->checkoutMode,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
