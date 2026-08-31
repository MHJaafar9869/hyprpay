<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout;

use Hyprpay\Payments\Domain\Command\CheckoutOptions;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Typed checkout options for CyberSource Unified Checkout — the `captureMandate` block.
 *
 * Configures which billing/contact fields the Unified Checkout widget collects and whether it
 * shows the accepted-network icons. The defaults reproduce the SDK's previous fixed behaviour
 * (full billing address, email requested, phone/shipping/network-icons off), so a request that
 * supplies no options is unchanged; pass a `CybersourceCheckoutOptions` on
 * `CheckoutSessionRequest::options` to adjust them. Ignored by Flex Microform, which renders no
 * billing UI.
 */
final readonly class CybersourceCheckoutOptions implements CheckoutOptions
{
    /**
     * @param  string  $billingType  Billing capture level: `FULL`, `PARTIAL` (name + country + postal), or `NONE` (name only).
     * @param  bool  $requestEmail  Whether the widget captures the customer's email address.
     * @param  bool  $requestPhone  Whether the widget captures the customer's phone number.
     * @param  bool  $requestShipping  Whether the widget captures shipping details.
     * @param  bool  $showAcceptedNetworkIcons  Whether the widget shows accepted-card-network icons beneath the pay button.
     */
    public function __construct(
        public string $billingType = 'FULL',
        public bool $requestEmail = true,
        public bool $requestPhone = false,
        public bool $requestShipping = false,
        public bool $showAcceptedNetworkIcons = false,
    ) {}

    /**
     * Resolve a request's options to CybersourceCheckoutOptions (typed object, legacy array, or defaults).
     */
    public static function fromRequest(CheckoutSessionRequest $request): self
    {
        return $request->options instanceof self ? $request->options : self::fromArray($request->optionsArray());
    }

    /**
     * Build the options from a raw captureMandate option-key array, falling back to the defaults.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            billingType: Value::string($options['billingType'] ?? null, 'FULL'),
            requestEmail: self::boolOr($options, 'requestEmail', true),
            requestPhone: self::boolOr($options, 'requestPhone', false),
            requestShipping: self::boolOr($options, 'requestShipping', false),
            showAcceptedNetworkIcons: self::boolOr($options, 'showAcceptedNetworkIcons', false),
        );
    }

    /**
     * Render the options as CyberSource's raw `captureMandate` key array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'billingType' => $this->billingType,
            'requestEmail' => $this->requestEmail,
            'requestPhone' => $this->requestPhone,
            'requestShipping' => $this->requestShipping,
            'showAcceptedNetworkIcons' => $this->showAcceptedNetworkIcons,
        ];
    }

    /**
     * Read a boolean option by key, or return the default when the key is absent.
     *
     * @param  array<string, mixed>  $options
     */
    private static function boolOr(array $options, string $key, bool $default): bool
    {
        return array_key_exists($key, $options) ? filter_var($options[$key], FILTER_VALIDATE_BOOLEAN) : $default;
    }
}
