<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Tamara\Payloads;

use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Renders the SDK's Money value object into Tamara's {amount, currency} money shape.
 *
 * Tamara expresses every amount as a JSON number in major units alongside its ISO
 * currency, so the exact minor-unit amount is emitted as its decimal value without rounding.
 */
final class TamaraMoney
{
    /**
     * Build Tamara's money object for the given amount.
     *
     * @return array{amount: float|int, currency: string}
     */
    public static function of(Money $money): array
    {
        return ['amount' => self::amount($money), 'currency' => $money->currency];
    }

    /**
     * Build a zero-valued Tamara money object in the amount's currency.
     *
     * @return array{amount: int, currency: string}
     */
    public static function zero(Money $money): array
    {
        return ['amount' => 0, 'currency' => $money->currency];
    }

    /**
     * Render the amount as a JSON number — an int for zero-decimal currencies, a float otherwise.
     */
    private static function amount(Money $money): float|int
    {
        $decimal = $money->toDecimalString();

        return $money->scale === 0 ? (int) $decimal : (float) $decimal;
    }
}
