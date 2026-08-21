<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

/**
 * A digital-wallet payment token supplied to a wallet charge, in one of two shapes.
 *
 * A wallet token can be forwarded to the gateway still encrypted for the gateway to
 * decrypt ({@see EncryptedWalletToken}), or decrypted by the merchant and forwarded as
 * a network token ({@see DecryptedWalletToken}). The gateway driver maps whichever shape
 * it receives to the corresponding request fields; the SDK itself never decrypts a token.
 */
interface WalletToken {}
