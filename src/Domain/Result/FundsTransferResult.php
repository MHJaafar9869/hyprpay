<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Result DTO for one leg of a funds transfer — a push (OCT) or a pull (AFT).
 *
 * A transfer is two legs, and they fail independently. A pull that succeeds followed by a push
 * that declines leaves the sender debited and the recipient unpaid, which is a reconciliation
 * problem rather than a failed transfer: the pull must be reversed, not retried. Treat each leg's
 * result as its own fact and record both.
 *
 * {@see $reconciliationId} is the value that ties the leg to the processor's own records, so it is
 * what a support conversation about a missing transfer will turn on — keep it even when the leg
 * succeeded.
 */
final readonly class FundsTransferResult
{
    /**
     * @param  bool  $success  Whether this leg succeeded
     * @param  PaymentStatus  $status  Normalised status for the leg
     * @param  string|null  $transferId  Gateway identifier for this leg, used to refund or reverse it
     * @param  string|null  $reconciliationId  Processor reconciliation reference, echoed back for support and settlement
     * @param  string|null  $code  Gateway response/status code
     * @param  string|null  $message  Human-readable gateway response message
     * @param  string|null  $approvalCode  Issuer approval code, when the transfer was approved
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public bool $success,
        public PaymentStatus $status,
        public ?string $transferId = null,
        public ?string $reconciliationId = null,
        public ?string $code = null,
        public ?string $message = null,
        public ?string $approvalCode = null,
        public array $raw = [],
    ) {}
}
