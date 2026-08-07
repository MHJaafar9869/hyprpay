<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry;

use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Raw card details for a Fawry card (PayUsingCC / instalment) charge.
 *
 * Collected on your own form and passed through {@see FawryCheckoutOptions::$card}; the
 * driver signs and forwards them to Fawry. Kept as a small typed value object so the four
 * card fields are named rather than buried in a nested `card` array.
 */
final readonly class FawryCard
{
    /**
     * @param  string  $number  Primary account number (PAN).
     * @param  string  $expiryYear  Two-digit expiry year (YY).
     * @param  string  $expiryMonth  Two-digit expiry month (MM).
     * @param  string  $cvv  Card verification value.
     */
    public function __construct(
        public string $number,
        public string $expiryYear,
        public string $expiryMonth,
        public string $cvv,
    ) {}

    /**
     * Build the card from a raw `card` option array.
     *
     * @param  array<string, mixed>  $card
     */
    public static function fromArray(array $card): self
    {
        return new self(
            number: Value::string($card['number'] ?? null),
            expiryYear: Value::string($card['expiryYear'] ?? null),
            expiryMonth: Value::string($card['expiryMonth'] ?? null),
            cvv: Value::string($card['cvv'] ?? null),
        );
    }

    /**
     * Render the card as Fawry's raw `card` option array.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'expiryYear' => $this->expiryYear,
            'expiryMonth' => $this->expiryMonth,
            'cvv' => $this->cvv,
        ];
    }
}
