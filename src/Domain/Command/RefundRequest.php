<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for refunding funds from a settled/captured transaction.
 *
 * Passed to the gateway's refund operation to return money (fully or partially) to the
 * payer for the transaction identified by its id.
 */
final readonly class RefundRequest
{
    /**
     * @param  string  $transactionId  Identifier of the captured transaction to refund
     * @param  Money  $money  Amount and currency to refund
     * @param  string|null  $reason  Optional reason for the refund
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     * @param  string|null  $idempotencyKey  Optional idempotency key; sent to the gateway so a retried refund is not double-processed. Supply a value unique to this refund (partial refunds need distinct keys).
     */
    public function __construct(
        public string $transactionId,
        public Money $money,
        public ?string $reason = null,
        public ?string $orderReference = null,
        public ?string $idempotencyKey = null,
    ) {}
}
