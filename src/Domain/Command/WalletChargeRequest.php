<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\DecryptedWalletToken;
use Hyprpay\Payments\Domain\ValueObject\EncryptedWalletToken;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\ValueObject\WalletToken;

/**
 * Input DTO for charging a digital-wallet payment token (Apple Pay / Google Pay).
 *
 * Carries the wallet token in one of two shapes — an {@see EncryptedWalletToken}
 * the gateway decrypts, or a {@see DecryptedWalletToken} the merchant decrypted
 * and forwards as a network token — together with the amount and optional billing / reconciliation context. The SDK
 * never decrypts the token itself. Passed to the gateway's chargeWallet operation.
 */
final readonly class WalletChargeRequest
{
    /**
     * @param  WalletToken  $token  The wallet token to charge (encrypted for the gateway to decrypt, or already-decrypted network-token fields)
     * @param  WalletType  $wallet  Which wallet produced the token, selecting the gateway's payment-solution mapping
     * @param  Money  $money  Amount and currency to charge
     * @param  bool  $capture  Whether to capture immediately (true) or authorise only (false)
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     * @param  BillingAddress|null  $billTo  Optional billing address for the payer
     * @param  string|null  $idempotencyKey  Optional idempotency key; sent to the gateway so a retried charge is not double-processed. Defaults to the order reference when omitted.
     * @param  DccQuote|null  $dcc  Optional DCC quote to bill the cardholder in their currency; set `money` to the quote's converted amount so the quoted rate is applied
     * @param  string|null  $deviceFingerprintId  Optional device fingerprint session id for fraud screening
     * @param  bool  $useRawFingerprintSessionId  When true, CyberSource uses the device fingerprint session id exactly as sent instead of the default merchant-prefixed lookup
     */
    public function __construct(
        public WalletToken $token,
        public WalletType $wallet,
        public Money $money,
        public bool $capture = true,
        public ?string $orderReference = null,
        public ?BillingAddress $billTo = null,
        public ?string $idempotencyKey = null,
        public ?DccQuote $dcc = null,
        public ?string $deviceFingerprintId = null,
        public bool $useRawFingerprintSessionId = false,
    ) {}
}
