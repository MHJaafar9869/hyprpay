<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Command\CreatePlanRequest;
use Hyprpay\Payments\Domain\Command\UpdatePlanRequest;
use Hyprpay\Payments\Domain\Enum\BillingPeriodUnit;
use Hyprpay\Payments\Domain\Enum\PlanStatus;
use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * @return array{0: CybersourceUnifiedCheckoutGateway, 1: FakeHttpClient}
 */
function planGateway(): array
{
    $http = new FakeHttpClient;

    return [new CybersourceUnifiedCheckoutGateway(testCredentials(), $http), $http];
}

/**
 * @return array<string, mixed>
 */
function planBody(FakeHttpClient $http): array
{
    return json_decode((string) $http->lastRequest()?->body, true) ?? [];
}

it('creates a plan carrying its cadence, cycle count, and price', function (): void {
    [$gateway, $http] = planGateway();
    $http->queueJson([
        'id' => 'plan_1',
        'status' => 'COMPLETED',
        'planInformation' => [
            'code' => 'PLAN-1',
            'status' => 'ACTIVE',
            'name' => 'Pro monthly',
            'billingPeriod' => ['length' => '1', 'unit' => 'M'],
            'billingCycles' => ['total' => '12'],
        ],
        'orderInformation' => ['amountDetails' => ['currency' => 'USD', 'billingAmount' => '49.99', 'setupFee' => '10.00']],
    ]);

    $plan = $gateway->createPlan(new CreatePlanRequest(
        name: 'Pro monthly',
        billingPeriod: BillingPeriod::monthly(),
        billingAmount: Money::minor(4999, 'USD'),
        setupFee: Money::minor(1000, 'USD'),
        billingCycles: 12,
        description: 'Pro tier, billed monthly',
    ));

    $request = $http->lastRequest();
    $body = planBody($http);

    expect($plan->success)->toBeTrue()
        ->and($plan->planId)->toBe('plan_1')
        ->and($plan->status)->toBe(PlanStatus::Active)
        ->and($plan->isSubscribable())->toBeTrue()
        ->and($plan->requestStatus)->toBe('COMPLETED')
        ->and($plan->billingPeriod?->unit)->toBe(BillingPeriodUnit::Month)
        ->and($plan->billingPeriod?->length)->toBe(1)
        ->and($plan->billingCycles)->toBe(12)
        ->and($plan->billingAmount?->toDecimalString())->toBe('49.99')
        ->and($plan->billingAmount?->currency)->toBe('USD')
        ->and($plan->setupFee?->toDecimalString())->toBe('10.00')
        ->and($request?->method)->toBe('POST')
        ->and($request?->url)->toBe('https://apitest.cybersource.com/rbs/v1/plans')
        ->and($body['planInformation']['name'])->toBe('Pro monthly')
        ->and($body['planInformation']['description'])->toBe('Pro tier, billed monthly')
        ->and($body['planInformation']['billingPeriod'])->toBe(['length' => '1', 'unit' => 'M'])
        ->and($body['planInformation']['billingCycles'])->toBe(['total' => '12'])
        ->and($body['orderInformation']['amountDetails'])->toBe([
            'currency' => 'USD', 'billingAmount' => '49.99', 'setupFee' => '10.00',
        ])
        ->and($body['planInformation'])->not->toHaveKey('status');
});

it('stages a plan as a draft when asked', function (): void {
    [$gateway, $http] = planGateway();

    $gateway->createPlan(new CreatePlanRequest(
        name: 'Staged', billingPeriod: BillingPeriod::yearly(), status: PlanStatus::Draft,
    ));

    expect(planBody($http)['planInformation']['status'])->toBe('DRAFT')
        ->and(planBody($http))->not->toHaveKey('orderInformation');
});

it('reads a plan back, treating an inactive one as a successful lookup but not subscribable', function (): void {
    [$gateway, $http] = planGateway();
    $http->queueJson([
        'id' => 'plan_1',
        'planInformation' => ['status' => 'INACTIVE', 'name' => 'Retired', 'billingPeriod' => ['length' => '3', 'unit' => 'M']],
    ]);

    $plan = $gateway->getPlan('plan_1');

    expect($plan->success)->toBeTrue()
        ->and($plan->status)->toBe(PlanStatus::Inactive)
        ->and($plan->isSubscribable())->toBeFalse()
        ->and($plan->requestStatus)->toBeNull()
        ->and($plan->billingPeriod?->length)->toBe(3)
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/rbs/v1/plans/plan_1');
});

it('lists plans', function (): void {
    [$gateway, $http] = planGateway();
    $http->queueJson(['plans' => [
        ['id' => 'plan_1', 'planInformation' => ['status' => 'ACTIVE', 'name' => 'A']],
        ['id' => 'plan_2', 'planInformation' => ['status' => 'DRAFT', 'name' => 'B']],
    ]]);

    $plans = $gateway->listPlans();

    expect($plans)->toHaveCount(2)
        ->and($plans[0]->planId)->toBe('plan_1')
        ->and($plans[0]->isSubscribable())->toBeTrue()
        ->and($plans[1]->status)->toBe(PlanStatus::Draft)
        ->and($plans[1]->isSubscribable())->toBeFalse();
});

it('returns an empty list when the merchant has no plans', function (): void {
    [$gateway] = planGateway();

    expect($gateway->listPlans())->toBe([]);
});

it('changes a plan billing period, which a live subscription cannot do', function (): void {
    [$gateway, $http] = planGateway();

    $gateway->updatePlan(new UpdatePlanRequest(
        planId: 'plan_1',
        billingPeriod: BillingPeriod::monthly(3),
        billingAmount: Money::minor(12999, 'USD'),
    ));

    $body = planBody($http);

    expect($http->lastRequest()?->method)->toBe('PATCH')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/rbs/v1/plans/plan_1')
        ->and($body['planInformation']['billingPeriod'])->toBe(['length' => '3', 'unit' => 'M'])
        ->and($body['orderInformation']['amountDetails'])->toBe(['currency' => 'USD', 'billingAmount' => '129.99'])
        ->and($body['planInformation'])->not->toHaveKey('name');
});

it('activates and deactivates a plan with bodyless signed posts', function (): void {
    [$gateway, $http] = planGateway();
    $http->queueJson(['id' => 'plan_1', 'status' => 'COMPLETED', 'planInformation' => ['status' => 'ACTIVE']]);

    $activated = $gateway->activatePlan('plan_1');

    expect($activated->status)->toBe(PlanStatus::Active)
        ->and($http->lastRequest()?->method)->toBe('POST')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/rbs/v1/plans/plan_1/activate')
        ->and($http->lastRequest()?->body)->toBe('');

    $http->queueJson(['id' => 'plan_1', 'status' => 'COMPLETED', 'planInformation' => ['status' => 'INACTIVE']]);

    expect($gateway->deactivatePlan('plan_1')->status)->toBe(PlanStatus::Inactive)
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/rbs/v1/plans/plan_1/deactivate');
});

it('deletes a plan and generates a plan code', function (): void {
    [$gateway, $http] = planGateway();

    expect($gateway->deletePlan('plan_1'))->toBeTrue()
        ->and($http->lastRequest()?->method)->toBe('DELETE')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/rbs/v1/plans/plan_1');

    $http->queueJson(['planInformation' => ['code' => 'PLAN-XYZ']]);

    expect($gateway->generatePlanCode())->toBe('PLAN-XYZ')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/rbs/v1/plans/code');
});

it('ignores a billing period the sdk does not model rather than inventing one', function (): void {
    [$gateway, $http] = planGateway();
    $http->queueJson(['id' => 'plan_1', 'planInformation' => ['status' => 'ACTIVE', 'billingPeriod' => ['length' => '0', 'unit' => 'Q']]]);

    expect($gateway->getPlan('plan_1')->billingPeriod)->toBeNull();
});

it('lists the payments a subscription has raised, for diagnosing a delinquent one', function (): void {
    [$gateway, $http] = planGateway();
    $http->queueJson(['totalCount' => 1, 'subscriptionPayments' => [['status' => 'DECLINED']]]);

    $payments = $gateway->listSubscriptionPayments('sub_1');

    expect(data_get($payments, 'subscriptionPayments.0.status'))->toBe('DECLINED')
        ->and($http->lastRequest()?->method)->toBe('GET')
        ->and($http->lastRequest()?->url)->toBe('https://apitest.cybersource.com/rbs/v1/subscriptions/sub_1/payments');
});
