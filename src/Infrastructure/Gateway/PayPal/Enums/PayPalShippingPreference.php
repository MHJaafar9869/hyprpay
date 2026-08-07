<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums;

/**
 * PayPal `experience_context.shipping_preference` — how PayPal handles the shipping address.
 *
 * {@see NoShipping} hides the address from the checkout (digital goods), {@see GetFromFile}
 * lets the buyer pick from the addresses in their PayPal account, and
 * {@see SetProvidedAddress} forces the merchant-provided address instead.
 */
enum PayPalShippingPreference: string
{
    case GetFromFile = 'GET_FROM_FILE';
    case NoShipping = 'NO_SHIPPING';
    case SetProvidedAddress = 'SET_PROVIDED_ADDRESS';
}
