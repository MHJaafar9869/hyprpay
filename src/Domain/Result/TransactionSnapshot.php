<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Result DTO representing the current state of a transaction as fetched from the gateway.
 *
 * Returned by the gateway's transaction lookup/reconciliation operation, giving the
 * transaction id, its normalised {@see PaymentStatus}, and optionally the amount and
 * order reference.
 */
final readonly class TransactionSnapshot
{
    /**
     * @param  string  $transactionId  Gateway transaction identifier being described
     * @param  PaymentStatus  $status  Normalised current status of the transaction
     * @param  Money|null  $money  Amount and currency of the transaction, when known
     * @param  string|null  $orderReference  Merchant order/reference number, when known
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public string $transactionId,
        public PaymentStatus $status,
        public ?Money $money = null,
        public ?string $orderReference = null,
        public array $raw = [],
    ) {}
}
