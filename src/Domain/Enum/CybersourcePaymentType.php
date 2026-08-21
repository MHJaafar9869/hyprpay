<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Payment types (`allowedPaymentTypes`) offered by a CyberSource Unified Checkout
 * capture context — the card/wallet checkout set CyberSource lists as the possible values when
 * launching Unified Checkout: manual PAN entry, Google Pay, Apple Pay, Click to Pay, eCheck, and Paze.
 *
 * CyberSource additionally accepts region- and entitlement-specific alternative payment methods
 * (BNPL such as AFTERPAY, online bank transfers such as IDEAL/BANCONTACT/P24, and post-pay references
 * such as KONBINI); those are not modelled here.
 */
enum CybersourcePaymentType: string
{
    case PanEntry = 'PANENTRY';
    case GooglePay = 'GOOGLEPAY';
    case ApplePay = 'APPLEPAY';
    case ClickToPay = 'CLICKTOPAY';
    case ECheck = 'CHECK';
    case Paze = 'PAZE';

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
