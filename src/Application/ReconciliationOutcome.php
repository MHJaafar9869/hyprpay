<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Application;

use Hyprpay\Payments\Domain\Result\TransactionSnapshot;

/**
 * Outcome of reconciling a single transaction id against its gateway.
 *
 * Pairs the requested transaction id with either the authoritative
 * {@see TransactionSnapshot} fetched from the gateway or the message explaining
 * why the lookup could not be reconciled, so a caller can report successes and
 * failures together without a single failed lookup aborting the whole batch.
 */
final readonly class ReconciliationOutcome
{
    /**
     * @param  string  $transactionId  The transaction id that was reconciled (as requested).
     * @param  TransactionSnapshot|null  $snapshot  The fetched snapshot, or null when the lookup failed.
     * @param  string|null  $error  The failure message, or null when the lookup succeeded.
     */
    public function __construct(
        public string $transactionId,
        public ?TransactionSnapshot $snapshot = null,
        public ?string $error = null,
    ) {}

    /**
     * Whether the transaction was successfully reconciled against the gateway.
     */
    public function reconciled(): bool
    {
        return $this->snapshot instanceof TransactionSnapshot;
    }
}
