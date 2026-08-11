<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for charging a previously vaulted payment instrument (stored credential).
 *
 * Passed to the gateway's stored-credential charge operation for merchant- or
 * customer-initiated transactions against a tokenised card, flagging whether this is
 * the first charge that establishes the credential-on-file relationship.
 */
final readonly class StoredCredentialChargeRequest
{
    /**
     * @param  string  $paymentInstrumentId  Vault identifier of the stored payment instrument to charge
     * @param  Money  $money  Amount and currency to charge
     * @param  CredentialInitiator  $initiator  Who initiated the charge (merchant or customer)
     * @param  bool  $isFirstCharge  Whether this is the initial charge establishing the credential on file
     * @param  string|null  $customerId  Optional vault customer identifier owning the instrument
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     * @param  string|null  $idempotencyKey  Optional idempotency key; sent to the gateway so a retried charge is not double-processed. Defaults to the order reference when omitted.
     * @param  string|null  $deviceFingerprintId  Optional device fingerprint session id for fraud screening
     * @param  bool  $useRawFingerprintSessionId  When true, CyberSource uses the device fingerprint session id exactly as sent instead of the default merchant-prefixed lookup
     */
    public function __construct(
        public string $paymentInstrumentId,
        public Money $money,
        public CredentialInitiator $initiator = CredentialInitiator::Merchant,
        public bool $isFirstCharge = false,
        public ?string $customerId = null,
        public ?string $orderReference = null,
        public ?string $idempotencyKey = null,
        public ?string $deviceFingerprintId = null,
        public bool $useRawFingerprintSessionId = false,
    ) {}
}
