<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for raising the amount already authorized on a transaction.
 *
 * For open-ended stays and rentals, where the final bill is not known when the card is presented:
 * a hotel authorizes an estimate on arrival and increments as nights and charges accumulate, so
 * one authorization covers the whole stay. That keeps a single, capturable hold on the card,
 * which is what makes it different from simply authorizing again — a second authorization would
 * place a second hold, doubling the funds withheld from the cardholder.
 *
 * {@see $additionalAmount} is the amount to ADD, not the new total.
 */
final readonly class IncrementAuthorizationRequest
{
    /**
     * @param  string  $transactionId  Identifier of the authorization to increase
     * @param  Money  $additionalAmount  Amount to add to the existing hold — not the new total
     * @param  string|null  $reason  Why the amount increased, for the processor's records
     * @param  string|null  $orderReference  Merchant order/reference number for reconciliation
     * @param  string|null  $idempotencyKey  Optional idempotency key so a retried increment is not applied twice
     */
    public function __construct(
        public string $transactionId,
        public Money $additionalAmount,
        public ?string $reason = null,
        public ?string $orderReference = null,
        public ?string $idempotencyKey = null,
    ) {}
}
