<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal;

use Hyprpay\Payments\Domain\Command\CheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalPaymentMethodPreference;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalShippingPreference;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalUserAction;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Typed checkout options for the PayPal order's `payment_source.paypal.experience_context`.
 *
 * Replaces the free-form options bag with the buyer-experience fields PayPal actually
 * accepts on an order: the cancel URL, the storefront brand name, a locale override, and
 * the enumerated shipping / approval-button / funding-method preferences. The return URL
 * and default locale come from the CheckoutSessionRequest itself, not from here.
 */
final readonly class PayPalCheckoutOptions implements CheckoutOptions
{
    /**
     * @param  string|null  $cancelUrl  Where PayPal returns the buyer if they cancel approval (defaults to the request's return URL).
     * @param  string|null  $brandName  Storefront name shown on the PayPal approval page.
     * @param  string|null  $locale  BCP-47 locale for the PayPal experience, overriding the request locale.
     * @param  PayPalShippingPreference|null  $shippingPreference  How PayPal handles the shipping address (defaults to NO_SHIPPING).
     * @param  PayPalUserAction|null  $userAction  Label/behaviour of the approval button (defaults to PAY_NOW).
     * @param  PayPalPaymentMethodPreference|null  $paymentMethodPreference  Which funding sources checkout allows.
     */
    public function __construct(
        public ?string $cancelUrl = null,
        public ?string $brandName = null,
        public ?string $locale = null,
        public ?PayPalShippingPreference $shippingPreference = null,
        public ?PayPalUserAction $userAction = null,
        public ?PayPalPaymentMethodPreference $paymentMethodPreference = null,
    ) {}

    /**
     * Build the options from a raw PayPal experience-context key array (backward compatibility).
     *
     * Lets callers that still pass an `options` array — using PayPal's own field names —
     * keep working; unknown enum values are dropped rather than throwing.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            cancelUrl: Value::nullableString($options['cancel_url'] ?? null),
            brandName: Value::nullableString($options['brand_name'] ?? null),
            locale: Value::nullableString($options['locale'] ?? null),
            shippingPreference: PayPalShippingPreference::tryFrom(Value::string($options['shipping_preference'] ?? null)),
            userAction: PayPalUserAction::tryFrom(Value::string($options['user_action'] ?? null)),
            paymentMethodPreference: PayPalPaymentMethodPreference::tryFrom(Value::string($options['payment_method_preference'] ?? null)),
        );
    }

    /**
     * Render the options as PayPal's experience-context key array, dropping unset fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'cancel_url' => $this->cancelUrl,
            'brand_name' => $this->brandName,
            'locale' => $this->locale,
            'shipping_preference' => $this->shippingPreference?->value,
            'user_action' => $this->userAction?->value,
            'payment_method_preference' => $this->paymentMethodPreference?->value,
        ], static fn (?string $value): bool => $value !== null);
    }
}
