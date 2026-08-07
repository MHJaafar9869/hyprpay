<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry;

use Hyprpay\Payments\Domain\Command\CheckoutOptions;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Typed checkout options for the Fawry gateway.
 *
 * Replaces Fawry's free-form options bag with named fields: the raw {@see FawryCard} for a
 * card / instalment charge, the wallet number for a mobile-wallet charge, the instalment
 * plan id, the customer's contact details used when they are not on the customer profile,
 * and the server-to-server webhook URL for the hosted flow.
 */
final readonly class FawryCheckoutOptions implements CheckoutOptions
{
    /**
     * @param  FawryCard|null  $card  Raw card details for a card (PayUsingCC) or instalment charge.
     * @param  string|null  $walletNumber  Mobile-wallet number for a MWALLET charge.
     * @param  string|null  $installmentPlanId  Bank instalment plan id for a CARD instalment charge.
     * @param  string|null  $customerEmail  Customer email, when not taken from the customer profile.
     * @param  string|null  $customerMobile  Customer mobile number.
     * @param  string|null  $webhookUrl  Server-to-server order webhook URL for the hosted checkout.
     */
    public function __construct(
        public ?FawryCard $card = null,
        public ?string $walletNumber = null,
        public ?string $installmentPlanId = null,
        public ?string $customerEmail = null,
        public ?string $customerMobile = null,
        public ?string $webhookUrl = null,
    ) {}

    /**
     * Resolve a request's options to FawryCheckoutOptions (typed object or legacy array).
     */
    public static function fromRequest(CheckoutSessionRequest $request): self
    {
        return $request->options instanceof self ? $request->options : self::fromArray($request->optionsArray());
    }

    /**
     * Build the options from a raw Fawry option-key array (backward compatibility).
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        $card = Value::array($options['card'] ?? null);

        return new self(
            card: $card === [] ? null : FawryCard::fromArray($card),
            walletNumber: Value::nullableString($options['wallet_number'] ?? null),
            installmentPlanId: Value::nullableString($options['installment_plan_id'] ?? null),
            customerEmail: Value::nullableString($options['customer_email'] ?? null),
            customerMobile: Value::nullableString($options['customer_mobile'] ?? null),
            webhookUrl: Value::nullableString($options['webhook_url'] ?? null),
        );
    }

    /**
     * Render the options as Fawry's raw option-key array, dropping unset fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'card' => $this->card?->toArray(),
            'wallet_number' => $this->walletNumber,
            'installment_plan_id' => $this->installmentPlanId,
            'customer_email' => $this->customerEmail,
            'customer_mobile' => $this->customerMobile,
            'webhook_url' => $this->webhookUrl,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
