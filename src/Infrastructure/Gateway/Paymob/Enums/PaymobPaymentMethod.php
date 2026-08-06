<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Enums;

/**
 * The Paymob acceptance methods the driver can start a checkout for.
 *
 * Each case keys into the merchant's per-method integration id and iframe id
 * (configured under the credentials' extra bag or supplied per request), which
 * select the payment product Paymob presents (card, ValU, or card instalments).
 */
enum PaymobPaymentMethod: string
{
    case Card = 'card';
    case Valu = 'valu';
    case Installment = 'installment';

    /**
     * Resolve a payment-method selector, defaulting to card.
     *
     * @param  string|null  $method  The requested method value, or null for the default.
     */
    public static function fromRequest(?string $method): self
    {
        return self::tryFrom($method ?? self::Card->value) ?? self::Card;
    }
}
