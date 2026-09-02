<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;

/**
 * Input DTO for cancelling a request whose reply never arrived.
 *
 * When a payment, capture, refund, or credit times out you cannot know whether it landed, and you
 * have no transaction id to void because the response that would have carried it never came. A
 * timeout void resolves that: it matches on the merchant transaction id you sent on the original
 * request and reverses it if it did land, or does nothing if it did not.
 *
 * It only works if that id was sent in the first place. Set `merchantTransactionId` on anything
 * you may need to undo blind — {@see ChargeRequest}, {@see CaptureRequest},
 * {@see RefundRequest}, {@see CreditRequest} all carry it — because it cannot be supplied
 * retrospectively.
 *
 * Distinct from reconciling by reference: {@see PaymentGatewayInterface::searchTransaction()}
 * and the reconcile helpers tell you *whether* a lost request settled, and are eventually
 * consistent; a timeout void *undoes* it, and acts immediately.
 */
final readonly class TimeoutVoidRequest
{
    /**
     * @param  string  $merchantTransactionId  The id sent as `clientReferenceInformation.transactionId` on the original request
     * @param  string|null  $orderReference  Merchant order/reference number for reconciliation
     * @param  string|null  $idempotencyKey  Optional idempotency key so a retried void is not applied twice
     */
    public function __construct(
        public string $merchantTransactionId,
        public ?string $orderReference = null,
        public ?string $idempotencyKey = null,
    ) {}
}
