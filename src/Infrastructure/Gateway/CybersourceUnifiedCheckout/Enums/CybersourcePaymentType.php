<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums;

/**
 * Payment types (`allowedPaymentTypes`) offered by a CyberSource Unified Checkout
 * capture context: manual PAN entry, Google Pay, Apple Pay, and Click to Pay.
 */
enum CybersourcePaymentType: string
{
    case PanEntry = 'PANENTRY';
    case GooglePay = 'GOOGLEPAY';
    case ApplePay = 'APPLEPAY';
    case ClickToPay = 'CLICKTOPAY';

    /**
     * Returns every payment-type string value, e.g. for populating the capture
     * context's `allowedPaymentTypes` list.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
