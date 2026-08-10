<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Outcome of a verified Unified Checkout v1 orchestrated (autoProcessing) payment.
 *
 * Returned once the completed-payment result JWT has been cryptographically verified and
 * validated against the order. Beyond the normalized status and transaction id, it carries
 * the reusable TMS token identifiers (instrument identifier / payment instrument / customer)
 * and network transaction id needed to charge the card again as a stored credential for
 * later installments, plus the card display metadata. When the payment was made with a
 * wallet (Apple Pay / Google Pay), no reusable credential is issued: {@see self::$isWallet}
 * is true and the token identifiers are null, signalling that installments must not be
 * scheduled against it.
 */
final readonly class OrchestratedPaymentResult
{
    /**
     * @param  bool  $success  Whether the verified status represents a successful outcome.
     * @param  PaymentStatus  $status  Normalized payment status mapped from the result JWT.
     * @param  string|null  $transactionId  CyberSource transaction id from the result JWT.
     * @param  string|null  $orderReference  Merchant order reference carried by the result JWT.
     * @param  string|null  $networkTransactionId  Network/processor transaction id for stored-credential reuse.
     * @param  bool  $isWallet  True for Apple Pay / Google Pay results, which yield no reusable credential.
     * @param  string|null  $instrumentIdentifierId  TMS instrument identifier id (null for wallet payments).
     * @param  string|null  $paymentInstrumentId  TMS payment instrument id (null for wallet payments).
     * @param  string|null  $customerId  TMS customer/token id (null for wallet payments).
     * @param  string|null  $cardBrand  Card brand (e.g. visa, mastercard) when present.
     * @param  string|null  $cardLast4  Last four digits of the card when present.
     * @param  string|null  $cardExpiryMonth  Card expiry month when present.
     * @param  string|null  $cardExpiryYear  Card expiry year when present.
     * @param  array<string, mixed>  $raw  The verified result JWT claims.
     */
    public function __construct(
        public bool $success,
        public PaymentStatus $status,
        public ?string $transactionId = null,
        public ?string $orderReference = null,
        public ?string $networkTransactionId = null,
        public bool $isWallet = false,
        public ?string $instrumentIdentifierId = null,
        public ?string $paymentInstrumentId = null,
        public ?string $customerId = null,
        public ?string $cardBrand = null,
        public ?string $cardLast4 = null,
        public ?string $cardExpiryMonth = null,
        public ?string $cardExpiryYear = null,
        public array $raw = [],
    ) {}
}
