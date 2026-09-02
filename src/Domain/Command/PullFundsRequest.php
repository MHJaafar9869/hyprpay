<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\BusinessApplicationId;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\ValueObject\TransferParty;

/**
 * Input DTO for debiting a card to fund a transfer — the pull half (an AFT).
 *
 * An Account Funding Transaction takes money *off* the sender's card so it can be pushed to
 * someone else. It is not a purchase and must not be modelled as one: the cardholder is not buying
 * anything, the networks price and rule it differently, and mis-declaring a funding transaction as
 * a sale is the usual cause of a transfer programme being shut down.
 *
 * The pair is the money-transfer pattern — pull from the sender with an AFT, push to the recipient
 * with an OCT ({@see PushFundsRequest}) — and the two carry the same
 * {@see $businessApplicationId} so the networks see one coherent transfer rather than an unrelated
 * debit and credit.
 */
final readonly class PullFundsRequest
{
    /**
     * @param  Money  $money  Amount and currency to debit from the sender's card
     * @param  string|null  $cardNumber  Sender's card number; prefer a token where you have one
     * @param  string|null  $paymentInstrumentId  Vault payment-instrument to debit instead of a raw card
     * @param  string|null  $expirationMonth  Sender card expiry month (`MM`), with a raw card number
     * @param  string|null  $expirationYear  Sender card expiry year (`YYYY`), with a raw card number
     * @param  string|null  $securityCode  Card verification value, where the corridor requires one
     * @param  BusinessApplicationId|null  $businessApplicationId  What the transfer is for; use the same value as the matching push
     * @param  TransferParty|null  $sender  Who is sending — the cardholder being debited
     * @param  TransferParty|null  $recipient  Who the funds are ultimately for, when the networks require it
     * @param  string|null  $orderReference  Merchant order/reference number for reconciliation
     * @param  string|null  $merchantTransactionId  Unique id you assign, so a lost reply can still be reversed
     * @param  string|null  $idempotencyKey  Optional idempotency key so a retried pull does not debit twice
     */
    public function __construct(
        public Money $money,
        public ?string $cardNumber = null,
        public ?string $paymentInstrumentId = null,
        public ?string $expirationMonth = null,
        public ?string $expirationYear = null,
        public ?string $securityCode = null,
        public ?BusinessApplicationId $businessApplicationId = null,
        public ?TransferParty $sender = null,
        public ?TransferParty $recipient = null,
        public ?string $orderReference = null,
        public ?string $merchantTransactionId = null,
        public ?string $idempotencyKey = null,
    ) {}
}
