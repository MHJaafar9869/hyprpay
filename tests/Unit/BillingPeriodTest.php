<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\BillingPeriodUnit;
use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;

it('renders the cadence as the string-typed fields cybersource expects', function (): void {
    expect(BillingPeriod::monthly()->toArray())->toBe(['length' => '1', 'unit' => 'M'])
        ->and(BillingPeriod::monthly(3)->toArray())->toBe(['length' => '3', 'unit' => 'M'])
        ->and(BillingPeriod::daily(7)->toArray())->toBe(['length' => '7', 'unit' => 'D'])
        ->and(BillingPeriod::weekly(2)->toArray())->toBe(['length' => '2', 'unit' => 'W'])
        ->and(BillingPeriod::yearly()->toArray())->toBe(['length' => '1', 'unit' => 'Y']);
});

it('keeps the length and unit it was built with', function (): void {
    $period = new BillingPeriod(6, BillingPeriodUnit::Month);

    expect($period->length)->toBe(6)
        ->and($period->unit)->toBe(BillingPeriodUnit::Month)
        ->and($period->unit->label())->toBe('Month');
});

it('rejects a period shorter than one unit', function (): void {
    expect(fn (): BillingPeriod => new BillingPeriod(0, BillingPeriodUnit::Month))
        ->toThrow(InvalidArgumentException::class, 'Billing period length must be at least 1.');
});
