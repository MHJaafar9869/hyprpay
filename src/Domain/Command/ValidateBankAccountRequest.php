<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

/**
 * Input DTO for the Visa Bank Account Validation Service (BAVS).
 *
 * Checks that a routing-number / account-number pair is a real, open account *before* an ACH
 * debit is attempted, which is how Nacha's account-validation mandate for WEB-debit
 * transactions is satisfied. It is a standalone check: nothing is charged, held, or
 * authorised, and it is not a payment.
 *
 * Supply either the raw bank details or a vaulted {@see $customerId} — with a customer token
 * the bank fields become optional, since the gateway reads the account behind the token. That
 * is the better path when the account is already vaulted: the raw numbers never leave storage.
 *
 * The routing and account numbers are sensitive banking credentials. They are sent to the
 * gateway and are never logged by the SDK — the logging decorator wraps only the shared
 * payment interface, which this operation deliberately sits outside of.
 */
final readonly class ValidateBankAccountRequest
{
    /**
     * Validation of both the routing number and the account number — the only level the
     * service documents.
     */
    public const LEVEL_ROUTING_AND_ACCOUNT = 1;

    /**
     * @param  string|null  $routingNumber  Bank routing (transit) number, digits only; optional when a vaulted customer is supplied
     * @param  string|null  $accountNumber  Bank account number, digits only; optional when a vaulted customer is supplied
     * @param  string|null  $customerId  Vault customer token holding the account, used instead of the raw bank details
     * @param  string|null  $paymentInstrumentId  Vault payment-instrument token for the account, when validating a specific stored instrument
     * @param  string|null  $instrumentIdentifierId  Vault instrument-identifier token for the account
     * @param  string|null  $orderReference  Merchant reference for reconciling the validation
     * @param  int  $validationLevel  Depth of validation to run; 1 checks routing and account number
     */
    public function __construct(
        public ?string $routingNumber = null,
        public ?string $accountNumber = null,
        public ?string $customerId = null,
        public ?string $paymentInstrumentId = null,
        public ?string $instrumentIdentifierId = null,
        public ?string $orderReference = null,
        public int $validationLevel = self::LEVEL_ROUTING_AND_ACCOUNT,
    ) {}
}
