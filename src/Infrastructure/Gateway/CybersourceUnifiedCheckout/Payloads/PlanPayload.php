<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\CreatePlanRequest;
use Hyprpay\Payments\Domain\Command\UpdatePlanRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Builds the CyberSource Recurring Billing (RBS) request bodies for the plan endpoints.
 *
 * A plan is the template a subscription is created from, so the body is purely descriptive:
 * cadence, cycle count, and price, with no customer and no card. {@see update()} is the partial
 * form — unlike a subscription, a plan's billing period *can* be changed, because nothing is
 * being billed against the plan itself.
 */
final class PlanPayload
{
    /**
     * Build the POST /rbs/v1/plans request body.
     *
     * @param  CreatePlanRequest  $request  The plan to create.
     * @return array<string, mixed>
     */
    public static function build(CreatePlanRequest $request): array
    {
        $planInformation = array_filter([
            'name' => $request->name,
            'description' => $request->description,
            'code' => $request->code,
            'status' => $request->status?->value,
            'billingPeriod' => $request->billingPeriod->toArray(),
            'billingCycles' => self::billingCycles($request->billingCycles),
        ], static fn (array|string|null $value): bool => $value !== null && $value !== '');

        return array_filter([
            'planInformation' => $planInformation,
            'orderInformation' => self::orderInformation($request->billingAmount, $request->setupFee),
        ], static fn (array $block): bool => $block !== []);
    }

    /**
     * Build the PATCH /rbs/v1/plans/{id} request body for a partial amend.
     *
     * @param  UpdatePlanRequest  $request  The fields to change on an existing plan.
     * @return array<string, mixed>
     */
    public static function update(UpdatePlanRequest $request): array
    {
        $planInformation = array_filter([
            'name' => $request->name,
            'description' => $request->description,
            'billingPeriod' => $request->billingPeriod?->toArray(),
            'billingCycles' => self::billingCycles($request->billingCycles),
        ], static fn (array|string|null $value): bool => $value !== null && $value !== '');

        return array_filter([
            'planInformation' => $planInformation,
            'orderInformation' => self::orderInformation($request->billingAmount, $request->setupFee),
        ], static fn (array $block): bool => $block !== []);
    }

    /**
     * The `planInformation.billingCycles` fragment, or null when no cycle count was supplied
     * (a plan that bills until its subscriptions are cancelled).
     *
     * @param  int|null  $billingCycles  Total cycles to bill.
     * @return array<string, string>|null
     */
    private static function billingCycles(?int $billingCycles): ?array
    {
        return $billingCycles === null ? null : ['total' => (string) $billingCycles];
    }

    /**
     * The `orderInformation` block for a plan's pricing.
     *
     * The currency comes from whichever amount was supplied, since a plan prices both in the
     * same currency. Empty when the plan carries no pricing of its own.
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
}
