<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry\Enums;

/**
 * The FawryPay checkout flows the driver supports.
 *
 * Chosen via CheckoutSessionRequest::$paymentMethod. `Hosted` is an SDK-internal
 * selector for the Express Checkout hosted page (init endpoint); the remaining
 * cases map to FawryPay's `paymentMethod` charge values (card 3DS, mobile wallet,
 * pay-at-outlet by reference number, the MyFawry app, and bank card instalments).
 */
enum FawryPaymentMethod: string
{
    case Hosted = 'hosted';
    case Card = 'PayUsingCC';
    case MobileWallet = 'MWALLET';
    case PayAtFawry = 'PAYATFAWRY';
    case MyFawry = 'MYFAWRY';
    case CardInstallment = 'CARD';

    /**
     * Resolve a payment-method selector, defaulting to hosted checkout.
     *
     * @param  string|null  $method  The requested method value, or null for the default.
     */
    public static function fromRequest(?string $method): self
    {
        return self::tryFrom($method ?? self::Hosted->value) ?? self::Hosted;
    }
}
