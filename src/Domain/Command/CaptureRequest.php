<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for capturing funds on a previously authorised transaction.
 *
 * Passed to the gateway's capture operation to settle (fully or partially) an
 * authorisation identified by its transaction id.
 */
final readonly class CaptureRequest
{
    /**
     * @param  string  $transactionId  Identifier of the authorisation to capture
     * @param  Money  $money  Amount and currency to capture (the cardholder's billing currency for a DCC capture)
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     * @param  string|null  $idempotencyKey  Optional idempotency key; sent to the gateway so a retried capture is not double-processed. Supply a value unique to this capture (partial captures need distinct keys).
     * @param  DccQuote|null  $dcc  Optional DCC quote from the authorization, to capture at the same quoted rate
     */
    public function __construct(
        public string $transactionId,
        public Money $money,
        public ?string $orderReference = null,
        public ?string $idempotencyKey = null,
        public ?DccQuote $dcc = null,
    ) {}
}
