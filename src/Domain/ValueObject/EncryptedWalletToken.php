<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

/**
 * A wallet token forwarded to the gateway still encrypted, for the gateway to decrypt.
 *
 * Carries the wallet's device-encrypted payment token exactly as the wallet delivered it
 * client-side; the gateway decrypts it and derives the card network itself, so the SDK
 * handles no cleartext PAN and no certificates. On CyberSource this maps to
 * paymentInformation.fluidData. Requires the wallet's payment-processing certificate to be
 * registered with the gateway's decryption service.
 */
final readonly class EncryptedWalletToken implements WalletToken
{
    /**
     * @param  string  $value  The wallet's device-encrypted payment token as delivered client-side (Apple Pay: the `paymentData` object serialized to JSON)
     */
    public function __construct(
        public string $value,
    ) {}
}
