<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\Enum\CybersourceCommerceIndicator;
use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for creating a recurring subscription against a vaulted payment instrument.
 *
 * The gateway bills the stored credential on its own schedule, so nothing here charges a
 * card directly: the subscription names the vault customer to bill, when the first charge
 * falls, and how often it repeats. The cadence comes either from a pre-built plan
 * ({@see $planId}) or inline from {@see $billingPeriod}/{@see $billingCycles} and
 * {@see $billingAmount} — supplying a plan id and overriding just the amount is a common
 * pattern for per-customer pricing on a shared plan.
 *
 * The vault customer must already exist: vault the card first with the gateway's tokenise
 * operation (or a checkout that creates a token), then pass the resulting customer id here.
 */
final readonly class CreateSubscriptionRequest
{
    /**
     * @param  string  $name  Human-readable subscription name shown in the gateway's back office
     * @param  string  $customerId  Vault customer identifier whose default instrument is billed each cycle
     * @param  string  $startDate  Date of the first charge, UTC — either `YYYY-MM-DD` or a full `YYYY-MM-DDThh:mm:ssZ` timestamp
     * @param  string|null  $planId  Identifier of an existing billing plan supplying the cadence and amount; omit to define them inline
     * @param  BillingPeriod|null  $billingPeriod  Inline billing cadence (e.g. every month), overriding the plan's period
     * @param  int|null  $billingCycles  Total number of cycles to bill before the subscription completes; null bills until cancelled
     * @param  Money|null  $billingAmount  Amount charged each cycle, overriding the plan's amount
     * @param  Money|null  $setupFee  One-off fee charged on the first cycle, in the same currency as the billing amount
     * @param  string|null  $code  Merchant-assigned subscription code; the gateway generates one when omitted
     * @param  string|null  $orderReference  Merchant order/reference number for reconciliation
     * @param  string|null  $originalTransactionId  Network transaction id of the initial cardholder-initiated charge that established the credential on file, threading this series to its CIT
     * @param  Money|null  $originalAuthorizedAmount  Amount of that initial charge; required by Diners and Discover
     * @param  CybersourceCommerceIndicator|null  $commerceIndicator  How the recurring agreement was taken; ignored when an original transaction id is supplied
     * @param  CredentialInitiator|null  $initiator  Who initiates the recurring charges; ignored when an original transaction id is supplied
     * @param  string|null  $idempotencyKey  Optional idempotency key so a retried create does not open a second subscription. Defaults to the order reference when omitted.
     */
    public function __construct(
        public string $name,
        public string $customerId,
        public string $startDate,
        public ?string $planId = null,
        public ?BillingPeriod $billingPeriod = null,
        public ?int $billingCycles = null,
        public ?Money $billingAmount = null,
        public ?Money $setupFee = null,
        public ?string $code = null,
        public ?string $orderReference = null,
        public ?string $originalTransactionId = null,
        public ?Money $originalAuthorizedAmount = null,
        public ?CybersourceCommerceIndicator $commerceIndicator = null,
        public ?CredentialInitiator $initiator = null,
        public ?string $idempotencyKey = null,
    ) {}
}
