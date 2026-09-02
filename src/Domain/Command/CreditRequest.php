<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for pushing money to a card with no original transaction behind it.
 *
 * A standalone credit, as distinct from a {@see RefundRequest}: a refund returns part or all of a
 * specific captured payment and is bounded by it, while a credit sends funds to a card outright —
 * a goodwill payment, a rebate, a settlement, or a refund whose original transaction is older than
 * the window the gateway will still refund against.
 *
 * That lack of a bound is the risk. Nothing caps the amount and nothing ties it to a sale, so a
 * credit is a common fraud target and processors watch them; gate it behind the same authorisation
 * you would put on a payout, not the one you put on a refund.
 */
final readonly class CreditRequest
{
    /**
     * @param  Money  $money  Amount and currency to credit to the card
     * @param  string|null  $transientToken  Transient token identifying the card to credit
     * @param  string|null  $paymentInstrumentId  Vault payment-instrument to credit instead of a token
     * @param  string|null  $customerId  Vault customer whose default instrument is credited
     * @param  string|null  $orderReference  Merchant order/reference number for reconciliation
     * @param  BillingAddress|null  $billTo  Billing address for the credit, when the processor requires one
     * @param  string|null  $merchantTransactionId  Unique id you assign to this credit, so it can be reversed with a timeout void if the reply is lost
     * @param  string|null  $idempotencyKey  Optional idempotency key so a retried credit does not pay out twice
     */
    public function __construct(
        public Money $money,
        public ?string $transientToken = null,
        public ?string $paymentInstrumentId = null,
        public ?string $customerId = null,
        public ?string $orderReference = null,
        public ?BillingAddress $billTo = null,
        public ?string $merchantTransactionId = null,
        public ?string $idempotencyKey = null,
    ) {}
}
