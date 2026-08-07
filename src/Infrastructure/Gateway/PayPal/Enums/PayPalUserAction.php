<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums;

/**
 * PayPal `experience_context.user_action` — the label of the approval button PayPal shows.
 *
 * {@see PayNow} completes the money movement when the buyer approves (the button reads
 * "Pay Now"); {@see Continue_} returns the buyer to the merchant to review before the
 * merchant captures/authorizes the order.
 */
enum PayPalUserAction: string
{
    case Continue_ = 'CONTINUE';
    case PayNow = 'PAY_NOW';
}
