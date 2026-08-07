<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob;

use Hyprpay\Payments\Domain\Command\CheckoutOptions;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Typed checkout options for the Paymob (Accept) gateway.
 *
 * Replaces Paymob's options bag with named fields: the per-method integration and iframe
 * ids (which otherwise fall back to the credentials' `extra` bag), the payment-key
 * expiry in seconds, and the customer's mobile number for the billing data.
 */
final readonly class PaymobCheckoutOptions implements CheckoutOptions
{
    /**
     * @param  int|string|null  $integrationId  Paymob integration id for the chosen method (overrides the credentials default).
     * @param  int|string|null  $iframeId  Paymob iframe id used to build the redirect URL (overrides the credentials default).
     * @param  int|null  $expiration  Payment-key lifetime in seconds.
     * @param  string|null  $customerMobile  Customer mobile number for the billing data.
     */
    public function __construct(
        public int|string|null $integrationId = null,
        public int|string|null $iframeId = null,
        public ?int $expiration = null,
        public ?string $customerMobile = null,
    ) {}

    /**
     * Resolve a request's options to PaymobCheckoutOptions (typed object or legacy array).
     */
    public static function fromRequest(CheckoutSessionRequest $request): self
    {
        return $request->options instanceof self ? $request->options : self::fromArray($request->optionsArray());
    }

    /**
     * Build the options from a raw Paymob option-key array (backward compatibility).
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            integrationId: self::scalarId($options['integration_id'] ?? null),
            iframeId: self::scalarId($options['iframe_id'] ?? null),
            expiration: isset($options['expiration']) ? Value::int($options['expiration']) : null,
            customerMobile: Value::nullableString($options['customer_mobile'] ?? null),
        );
    }

    /**
     * Render the options as Paymob's raw option-key array, dropping unset fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'integration_id' => $this->integrationId,
            'iframe_id' => $this->iframeId,
            'expiration' => $this->expiration,
            'customer_mobile' => $this->customerMobile,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Narrow a raw id value to an int, a non-empty string, or null.
     *
     * @param  mixed  $value
     */
    private static function scalarId($value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        return Value::nullableString($value);
    }
}
