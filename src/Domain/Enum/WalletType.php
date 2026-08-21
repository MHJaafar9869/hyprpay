<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Digital wallet whose device-encrypted payment token is being charged.
 *
 * A wallet token (Apple Pay / Google Pay) is captured client-side by the wallet's
 * own button and carries its own network-token cryptogram. The SDK forwards the
 * encrypted token to the gateway for decryption and authorization; the gateway
 * driver maps each wallet to its provider-specific payment-solution identifier.
 */
enum WalletType: string
{
    case ApplePay = 'apple_pay';
    case GooglePay = 'google_pay';
}
