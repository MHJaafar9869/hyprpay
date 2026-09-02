<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;

/**
 * Calendar unit a recurring billing period is measured in.
 *
 * Paired with a length in {@see BillingPeriod} to express how often a subscription
 * bills — length 1 + Month charges monthly, length 7 + Day charges every seventh day.
 * The backing values are the single-character codes CyberSource Recurring Billing
 * expects in `planInformation.billingPeriod.unit`.
 */
enum BillingPeriodUnit: string
{
    case Day = 'D';
    case Week = 'W';
    case Month = 'M';
    case Year = 'Y';

    /**
     * Human-readable display name for the unit, for UIs and logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::Day => 'Day',
            self::Week => 'Week',
            self::Month => 'Month',
            self::Year => 'Year',
        };
    }
}
