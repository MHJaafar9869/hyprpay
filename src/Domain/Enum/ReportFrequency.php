<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * How often a scheduled gateway report is generated.
 *
 * A subscription carries one of the recurring cadences; {@see self::Adhoc} is not
 * schedulable — it is the frequency a one-off report created on demand reports back as.
 */
enum ReportFrequency: string
{
    case Daily = 'DAILY';
    case Weekly = 'WEEKLY';
    case Monthly = 'MONTHLY';
    case UserDefined = 'USER_DEFINED';
    case Adhoc = 'ADHOC';

    /**
     * Whether the cadence needs a start day alongside the start time.
     *
     * Weekly subscriptions take a day of the week (1 Sunday – 7 Saturday) and monthly ones
     * a day of the month (1–31); daily and interval-driven schedules do not.
     */
    public function needsStartDay(): bool
    {
        return match ($this) {
            self::Weekly, self::Monthly => true,
            self::Daily, self::UserDefined, self::Adhoc => false,
        };
    }

    /**
     * Whether the cadence is driven by an explicit interval rather than a calendar rule,
     * in which case a subscription must also carry an ISO 8601 duration (e.g. `PT2H30M`).
     */
    public function needsInterval(): bool
    {
        return $this === self::UserDefined;
    }
}
