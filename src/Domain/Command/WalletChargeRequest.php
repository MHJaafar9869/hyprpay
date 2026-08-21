<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for charging a digital-wallet payment token (Apple Pay / Google Pay).
 *
 * Carries the device-encrypted wallet token exactly as the wallet button delivered it,
 * together with the amount and optional billing/reconciliation context. The token is
 * forwarded to the gateway, which decrypts it and authorizes the payment; the SDK never
 * decrypts the token or handles the cleartext PAN itself. Passed to the gateway's
 * chargeWallet operation.
 */
final readonly class WalletChargeRequest
{
    /**
     * @param  string  $encryptedToken  The wallet's device-encrypted payment token as delivered client-side (Apple Pay: the `paymentData` object serialized to JSON)
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
        public string $encryptedToken,
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
