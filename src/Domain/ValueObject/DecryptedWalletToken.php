<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

/**
 * A wallet token the merchant has already decrypted into its network-token fields.
 *
 * Carries the decrypted device primary account number (DPAN), the online payment
 * cryptogram, expiry, and optional ECI and card-network type. The merchant decrypts the
 * wallet payload (holding the wallet's payment-processing certificate); the SDK forwards
 * these fields without ever performing the decryption. On CyberSource this maps to
 * paymentInformation.tokenizedCard with transactionType 1 (in-app wallet tokenization).
 */
final readonly class DecryptedWalletToken implements WalletToken
{
    /**
     * @param  string  $number  Device primary account number (DPAN) from the decrypted token
     * @param  string  $cryptogram  Online payment cryptogram from the decrypted token
     * @param  string  $expiryMonth  Two-digit expiry month (`MM`)
     * @param  string  $expiryYear  Four-digit expiry year (`YYYY`) preferred
     * @param  string|null  $eci  Electronic Commerce Indicator from the decrypted token, when present
     * @param  string|null  $cardType  Gateway card-network code (e.g. CyberSource `001` Visa, `002` Mastercard); omitted when null so the gateway is not told the wrong network
     */
    public function __construct(
        public string $number,
        public string $cryptogram,
        public string $expiryMonth,
        public string $expiryYear,
        public ?string $eci = null,
        public ?string $cardType = null,
    ) {}
}
