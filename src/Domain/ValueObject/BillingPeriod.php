<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

use Hyprpay\Payments\Domain\Command\CreateSubscriptionRequest;
use Hyprpay\Payments\Domain\Enum\BillingPeriodUnit;
use InvalidArgumentException;

/**
 * Immutable value object describing how often a subscription bills.
 *
 * A billing period is a length plus a calendar unit — 1 Month charges monthly, 7 Day
 * charges every seventh day, 3 Month charges quarterly. Attached to a
 * {@see CreateSubscriptionRequest} to define the cadence inline, it maps to the
 * CyberSource `planInformation.billingPeriod` block; omit it and the subscription
 * inherits the cadence of the plan it references.
 */
final readonly class BillingPeriod
{
    /**
     * @param  int  $length  How many units make up one billing period (must be at least 1)
     * @param  BillingPeriodUnit  $unit  Calendar unit the length is counted in
     *
     * @throws InvalidArgumentException When the length is less than 1
     */
    public function __construct(
        public int $length,
        public BillingPeriodUnit $unit,
    ) {
        if ($this->length < 1) {
            throw new InvalidArgumentException('Billing period length must be at least 1.');
        }
    }

    /**
     * Named constructor for a period counted in days (default: every day).
     *
     * @param  int  $length  Number of days between charges
     */
    public static function daily(int $length = 1): self
    {
        return new self($length, BillingPeriodUnit::Day);
    }

    /**
     * Named constructor for a period counted in weeks (default: every week).
     *
     * @param  int  $length  Number of weeks between charges
     */
    public static function weekly(int $length = 1): self
    {
        return new self($length, BillingPeriodUnit::Week);
    }

    /**
     * Named constructor for a period counted in months (default: every month).
     *
     * @param  int  $length  Number of months between charges (3 for quarterly)
     */
    public static function monthly(int $length = 1): self
    {
        return new self($length, BillingPeriodUnit::Month);
    }

    /**
     * Named constructor for a period counted in years (default: every year).
     *
     * @param  int  $length  Number of years between charges
     */
    public static function yearly(int $length = 1): self
    {
        return new self($length, BillingPeriodUnit::Year);
    }

    /**
     * The CyberSource `planInformation.billingPeriod` fields.
     *
     * Both values are rendered as strings, which is how the Recurring Billing API
     * types them.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'length' => (string) $this->length,
            'unit' => $this->unit->value,
        ];
    }
}
