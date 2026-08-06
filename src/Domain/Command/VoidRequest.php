<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

/**
 * Input DTO for voiding an uncaptured transaction.
 *
 * Passed to the gateway's void operation to cancel a payment (such as a capture or
 * credit) that has not yet settled, identified by its transaction id.
 */
final readonly class VoidRequest
{
    /**
     * @param  string  $transactionId  Identifier of the transaction to void
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     * @param  string|null  $idempotencyKey  Optional idempotency key; sent to the gateway so a retried void is not double-processed.
     */
    public function __construct(
        public string $transactionId,
        public ?string $orderReference = null,
        public ?string $idempotencyKey = null,
    ) {}
}
