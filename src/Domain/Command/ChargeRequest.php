<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Installment;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for charging a card captured by the Unified Checkout widget.
 *
 * Carries the transient token produced by the widget together with the amount and
 * optional billing, customer, and 3DS authentication context. Passed to the gateway's
 * charge/authorise operation to create a payment.
 */
final readonly class ChargeRequest
{
    /**
     * @param  string  $transientToken  One-time token from the Unified Checkout widget representing the entered card
     * @param  Money  $money  Amount and currency to charge
     * @param  bool  $capture  Whether to capture immediately (true) or authorise only (false)
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     * @param  BillingAddress|null  $billTo  Optional billing address for the payer
     * @param  Customer|null  $customer  Optional customer identity to associate with the charge
     * @param  array<string, mixed>  $consumerAuthentication  3DS cryptogram fields (cavv, eci, xid, …) when authenticated
     * @param  string|null  $commerceIndicator  Optional commerce indicator overriding the default transaction type
     * @param  string|null  $deviceFingerprintId  Optional device fingerprint session id for fraud screening
     * @param  string|null  $idempotencyKey  Optional idempotency key; sent to the gateway so a retried charge is not double-processed. Defaults to the order reference when omitted.
     * @param  DccQuote|null  $dcc  Optional DCC quote to bill the cardholder in their currency; set `money` to the quote's converted amount so the quoted rate is applied
     * @param  bool  $useRawFingerprintSessionId  When true, CyberSource uses the device fingerprint session id exactly as sent instead of the default merchant-prefixed lookup
     * @param  Installment|null  $installment  Optional issuer-funded installment plan to split the charge across (maps to processingInformation.installment)
     * @param  string|null  $merchantTransactionId  Unique id you assign to this request; required on the original call if you may later need a timeout void or reversal
     */
    public function __construct(
        public string $transientToken,
        public Money $money,
        public bool $capture = true,
        public ?string $orderReference = null,
        public ?BillingAddress $billTo = null,
        public ?Customer $customer = null,
        public array $consumerAuthentication = [],
        public ?string $commerceIndicator = null,
        public ?string $deviceFingerprintId = null,
        public ?string $idempotencyKey = null,
        public ?DccQuote $dcc = null,
        public bool $useRawFingerprintSessionId = false,
        public ?Installment $installment = null,
        public ?string $merchantTransactionId = null,
    ) {}
}
