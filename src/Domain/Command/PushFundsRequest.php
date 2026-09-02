<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\BusinessApplicationId;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\ValueObject\TransferParty;

/**
 * Input DTO for crediting a card — the push half of a funds transfer (an OCT).
 *
 * An Original Credit Transaction puts money *onto* a card, typically within seconds, which is what
 * separates it from a refund or a credit: those move money back along the rail a sale came in on,
 * while this pushes funds to any eligible card regardless of whether that card ever paid you.
 *
 * {@see $businessApplicationId} is the field to get right. It tells the networks what the transfer
 * is for, and it drives interchange, the rules the transfer is judged against, and in some markets
 * whether it is allowed at all. Declaring a payroll disbursement as a person-to-person transfer is
 * a compliance problem, not a mislabelling.
 *
 * For a money transfer the networks also require the **sender** to be identified — name, address,
 * usually date of birth — so the transaction can be screened. Omitting it is refused at the
 * network, not declined by the issuer. A funds disbursement generally does not need it.
 */
final readonly class PushFundsRequest
{
    /**
     * @param  Money  $money  Amount and currency to credit to the recipient's card
     * @param  string|null  $cardNumber  Recipient's card number; prefer a token where you have one
     * @param  string|null  $paymentInstrumentId  Vault payment-instrument to credit instead of a raw card
     * @param  string|null  $expirationMonth  Recipient card expiry month (`MM`), with a raw card number
     * @param  string|null  $expirationYear  Recipient card expiry year (`YYYY`), with a raw card number
     * @param  BusinessApplicationId|null  $businessApplicationId  What the transfer is for; the merchant's configured default applies when omitted
     * @param  TransferParty|null  $sender  Who is sending — required for money transfers, screened by the networks
     * @param  TransferParty|null  $recipient  Who is receiving; card transfers need at least a name and address
     * @param  string|null  $purposeOfPayment  Purpose-of-funds code, where the corridor requires one
     * @param  string|null  $orderReference  Merchant order/reference number for reconciliation
     * @param  string|null  $merchantTransactionId  Unique id you assign, so a lost reply can still be reversed
     * @param  string|null  $idempotencyKey  Optional idempotency key so a retried push does not pay out twice
     */
    public function __construct(
        public Money $money,
        public ?string $cardNumber = null,
        public ?string $paymentInstrumentId = null,
        public ?string $expirationMonth = null,
        public ?string $expirationYear = null,
        public ?BusinessApplicationId $businessApplicationId = null,
        public ?TransferParty $sender = null,
        public ?TransferParty $recipient = null,
        public ?string $purposeOfPayment = null,
        public ?string $orderReference = null,
        public ?string $merchantTransactionId = null,
        public ?string $idempotencyKey = null,
    ) {}
}
