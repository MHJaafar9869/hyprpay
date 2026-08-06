<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for reversing (releasing) a prior authorisation.
 *
 * Passed to the gateway's authorisation-reversal operation to undo an authorisation
 * hold and release the reserved funds before capture.
 */
final readonly class ReversalRequest
{
    /**
     * @param  string  $transactionId  Identifier of the authorisation to reverse
     * @param  Money  $money  Amount and currency to reverse
     * @param  string|null  $reason  Optional reason for the reversal
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     * @param  string|null  $idempotencyKey  Optional idempotency key; sent to the gateway so a retried reversal is not double-processed.
     */
    public function __construct(
        public string $transactionId,
        public Money $money,
        public ?string $reason = null,
        public ?string $orderReference = null,
        public ?string $idempotencyKey = null,
    ) {}
}
