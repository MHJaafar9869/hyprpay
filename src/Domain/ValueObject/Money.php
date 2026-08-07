<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Immutable money value object holding an amount in minor units plus its currency.
 *
 * The amount is always stored as an integer number of minor units (subunits) at a
 * given decimal scale, so no precision is ever lost to floating point or rounding.
 * Used throughout the SDK to express charge, capture, refund, and reversal amounts.
 */
final readonly class Money
{
    /**
     * @param  int  $minorAmount  Amount expressed in minor units/subunits (e.g. 10000 for 100.00 at scale 2)
     * @param  string  $currency  ISO 4217 currency code (case is preserved as given)
     * @param  int  $scale  Number of decimal places the currency uses (2 for most, 0 for zero-decimal currencies)
     *
     * @throws InvalidArgumentException When the scale is negative
     */
    public function __construct(
        public int $minorAmount,
        public string $currency,
        public int $scale = 2,
    ) {
        if ($this->scale < 0) {
            throw new InvalidArgumentException('Money scale cannot be negative.');
        }
    }

    /**
     * Named constructor building a Money from an amount already in minor units.
     *
     * Uppercases the given currency code before storing it.
     *
     * @param  int  $minorAmount  Amount expressed in minor units/subunits
     * @param  string  $currency  ISO 4217 currency code (normalised to uppercase)
     * @param  int  $scale  Number of decimal places the currency uses
     */
    public static function minor(int $minorAmount, string $currency, int $scale = 2): self
    {
        return new self($minorAmount, strtoupper($currency), $scale);
    }

    /**
     * Named constructor building a Money from an exact decimal string (e.g. "100.00").
     *
     * The scale is inferred from the number of fractional digits ("9.60" → scale 2,
     * "480" → scale 0), so {@see toDecimalString()} round-trips the value byte-for-byte.
     * The currency is normalised to uppercase and no rounding is performed. Used to read
     * the decimal amounts that gateways (e.g. CyberSource DCC) return as strings.
     *
     * @param  string  $amount  Decimal amount, optionally signed (e.g. "-12.500")
     * @param  string  $currency  ISO 4217 currency code (normalised to uppercase)
     *
     * @throws InvalidArgumentException When the amount is not a valid decimal string
     */
    public static function fromDecimalString(string $amount, string $currency): self
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new InvalidArgumentException("Invalid decimal amount: {$amount}");
        }

        $isNegative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');

        $minorAmount = (int) ($whole.$fraction);

        return new self($isNegative ? -$minorAmount : $minorAmount, strtoupper($currency), strlen($fraction));
    }

    /**
     * Render the amount as an exact decimal string (e.g. "100.00") without rounding.
     *
     * Splits the absolute minor amount into whole and fractional parts using integer
     * division by 10^scale, left-pads the fraction to the scale width, and re-applies a
     * leading minus for negative amounts. A scale of 0 returns the integer as-is with no
     * decimal point. The result preserves full precision — no rounding is performed.
     */
    public function toDecimalString(): string
    {
        if ($this->scale === 0) {
            return (string) $this->minorAmount;
        }

        $isNegative = $this->minorAmount < 0;
        $absolute = abs($this->minorAmount);
        $divisor = 10 ** $this->scale;

        $whole = intdiv($absolute, $divisor);
        $fraction = str_pad((string) ($absolute % $divisor), $this->scale, '0', STR_PAD_LEFT);
        $sign = $isNegative ? '-' : '';

        return "{$sign}{$whole}.{$fraction}";
    }
}
