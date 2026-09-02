<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\CreateSubscriptionRequest;
use Hyprpay\Payments\Domain\Command\ListSubscriptionsRequest;
use Hyprpay\Payments\Domain\Command\UpdateSubscriptionRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\Enum\SubscriptionStatus;
use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums\CybersourceSubscriptionStatus;

/**
 * Builds the CyberSource Recurring Billing (RBS) requests for the subscription endpoints.
 *
 * A subscription bills a vaulted TMS customer on a schedule CyberSource runs itself, so the
 * create body carries no card data and no immediate charge: it names the customer token to bill,
 * when the series starts, and how often it repeats — either by referencing an existing plan
 * or by defining the cadence and amount inline. Every optional block is omitted rather than
 * sent empty, so a request that only references a plan stays as small as CyberSource expects.
 *
 * {@see buildUpdate()} produces the narrower PATCH body (a partial amend), and {@see query()}
 * the filter string for the paged list.
 */
final class SubscriptionPayload
{
    /**
     * Build the POST /rbs/v1/subscriptions request body.
     *
     * Always emits the client reference code, the subscription block (name, normalised UTC
     * start date, and any plan id, code, or original-transaction linkage), and the vault
     * customer to bill. Processing information, the inline plan cadence, and the amount
     * details are added only when the request supplies them — CyberSource ignores the
     * commerce indicator and initiator anyway once an original transaction id is present.
     *
     * @param  CreateSubscriptionRequest  $request  Subscription inputs (name, vault customer, start date, plan or inline cadence, amounts, and stored-credential linkage).
     * @return array<string, mixed>
     */
    public static function build(CreateSubscriptionRequest $request): array
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => ClientReference::code($request->orderReference, $request->customerId),
            ],
            'subscriptionInformation' => array_filter([
                'name' => $request->name,
                'startDate' => self::startDate($request->startDate),
                'planId' => $request->planId,
                'code' => $request->code,
                'originalTransactionId' => $request->originalTransactionId,
                'originalTransactionAuthorizedAmount' => $request->originalAuthorizedAmount?->toDecimalString(),
            ], filled(...)),
            'paymentInformation' => [
                'customer' => ['id' => $request->customerId],
            ],
            'processingInformation' => self::processingInformation($request),
            'planInformation' => self::planInformation($request->billingPeriod, $request->billingCycles),
            'orderInformation' => self::orderInformation($request->billingAmount, $request->setupFee),
        ];

        return array_filter($payload, static fn (array $block): bool => $block !== []);
    }

    /**
     * The `processingInformation` block declaring how the recurring agreement was taken.
     *
     * Empty unless a commerce indicator or an initiator was supplied, since CyberSource
     * applies its own defaults — and ignores both fields outright when the subscription
     * links to an original transaction id.
     *
     * @return array<string, mixed>
     */
    private static function processingInformation(CreateSubscriptionRequest $request): array
    {
        $initiator = $request->initiator;

        return array_filter([
            'commerceIndicator' => $request->commerceIndicator?->value,
            'authorizationOptions' => $initiator instanceof CredentialInitiator
                ? ['initiator' => ['type' => $initiator->value]]
                : null,
        ], static fn (array|string|null $value): bool => $value !== null);
    }

    /**
     * The `planInformation` block overriding the billing cadence inline.
     *
     * Empty when neither a period nor a cycle count was supplied, leaving the referenced
     * plan's own cadence to apply.
     *
     * @param  BillingPeriod|null  $billingPeriod  How often the subscription charges.
     * @param  int|null  $billingCycles  How many cycles to bill before completing.
     * @return array<string, mixed>
     */
    private static function planInformation(?BillingPeriod $billingPeriod, ?int $billingCycles): array
    {
        return array_filter([
            'billingPeriod' => $billingPeriod?->toArray(),
            'billingCycles' => $billingCycles === null ? null : ['total' => (string) $billingCycles],
        ], static fn (?array $value): bool => $value !== null);
    }

    /**
     * The `orderInformation.amountDetails` block for a subscription's money fields.
     *
     * The currency is taken from whichever amount was supplied — both are charged on the same
     * card in the same currency — so passing only a setup fee still sends a well-formed block.
     * Empty when neither amount is set, leaving the plan's own pricing to apply.
     *
     * @param  Money|null  $billingAmount  Amount charged each cycle.
     * @param  Money|null  $setupFee  One-off fee charged on the first cycle.
     * @return array<string, mixed>
     */
    private static function orderInformation(?Money $billingAmount, ?Money $setupFee): array
    {
        $amountDetails = array_filter([
            'currency' => ($billingAmount ?? $setupFee)?->currency,
            'billingAmount' => $billingAmount?->toDecimalString(),
            'setupFee' => $setupFee?->toDecimalString(),
        ], filled(...));

        return $amountDetails === [] ? [] : ['amountDetails' => $amountDetails];
    }

    /**
     * Build the PATCH /rbs/v1/subscriptions/{id} request body for a partial amend.
     *
     * Only the fields the caller set are emitted, so an untouched field keeps its current value.
     * The body is deliberately narrower than a create's: CyberSource's update schema accepts
     * `billingCycles` but no `billingPeriod` (the cadence is fixed once the subscription exists)
     * and amount details without a currency (a subscription bills in the currency it was created
     * with), so a supplied Money contributes its amount and its currency is ignored. Processing
     * information is not sent at all — CyberSource ignores it on an update.
     *
     * @param  UpdateSubscriptionRequest  $request  The fields to change on an existing subscription.
     * @return array<string, mixed>
     */
    public static function buildUpdate(UpdateSubscriptionRequest $request): array
    {
        $subscriptionInformation = array_filter([
            'name' => $request->name,
            'planId' => $request->planId,
            'code' => $request->code,
            'startDate' => $request->startDate === null ? null : self::startDate($request->startDate),
        ], filled(...));

        $amountDetails = array_filter([
            'billingAmount' => $request->billingAmount?->toDecimalString(),
            'setupFee' => $request->setupFee?->toDecimalString(),
        ], filled(...));

        $payload = [
            'clientReferenceInformation' => filled($request->orderReference)
                ? ['code' => ClientReference::code($request->orderReference)]
                : [],
            'subscriptionInformation' => $subscriptionInformation,
            'planInformation' => $request->billingCycles === null
                ? []
                : ['billingCycles' => ['total' => (string) $request->billingCycles]],
            'orderInformation' => $amountDetails === [] ? [] : ['amountDetails' => $amountDetails],
        ];

        return array_filter($payload, static fn (array $block): bool => $block !== []);
    }

    /**
     * Build the query string for GET /rbs/v1/subscriptions, including the leading `?`.
     *
     * Only filters the caller set are sent; the status filter is mapped back to CyberSource's own
     * spelling. Values are URL-encoded, and paging is always sent so the window a page came from
     * is explicit rather than left to the gateway's defaults. Returns an empty string when there
     * is nothing to send, so the caller can concatenate it unconditionally.
     *
     * @param  ListSubscriptionsRequest  $request  Filters and paging for the list.
     */
    public static function query(ListSubscriptionsRequest $request): string
    {
        $parameters = array_filter([
            'offset' => (string) $request->offset,
            'limit' => (string) $request->limit,
            'code' => $request->code,
            'status' => $request->status instanceof SubscriptionStatus
                ? CybersourceSubscriptionStatus::fromSubscriptionStatus($request->status)->value
                : null,
            'customerId' => $request->customerId,
            'clientReferenceInformationCode' => $request->orderReference,
        ], filled(...));

        return $parameters === [] ? '' : '?'.http_build_query($parameters);
    }

    /**
     * Normalises a start date to the UTC timestamp CyberSource requires.
     *
     * A bare `YYYY-MM-DD` is expanded to midnight UTC (`YYYY-MM-DDT00:00:00Z`) so the common
     * calendar-date form is accepted rather than rejected as a malformed field; anything
     * already carrying a time is passed through untouched.
     *
     * @param  string  $startDate  Caller-supplied start date.
     */
    private static function startDate(string $startDate): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1
            ? $startDate.'T00:00:00Z'
            : $startDate;
    }
}
