<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\ReportFormat;
use Hyprpay\Payments\Domain\Enum\ReportFrequency;

/**
 * Result DTO describing a standing subscription that makes the gateway generate a report
 * on a recurring schedule.
 *
 * Distinct from {@see Report}, which is one run the schedule produced: this is the schedule
 * itself — what report runs, how often, in what format, and which columns each run carries.
 */
final readonly class ReportSubscription
{
    /**
     * @param  string|null  $name  Unique name of the subscription, and the key its runs are downloaded by
     * @param  string|null  $definitionName  Report definition the schedule runs
     * @param  ReportFrequency|null  $frequency  How often the report is generated
     * @param  ReportFormat|null  $format  File format each run is generated in
     * @param  string|null  $startTime  Time of day each run starts, as `hhmm`
     * @param  int|null  $startDay  Day the schedule runs on for a weekly or monthly cadence
     * @param  string|null  $timezone  Timezone the schedule runs in
     * @param  array<int, string>  $fields  Columns included in each generated report
     * @param  string|null  $subscriptionType  Whether the subscription is Custom, Standard, or Classic
     * @param  string|null  $organizationId  Organization the subscription belongs to
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public ?string $name = null,
        public ?string $definitionName = null,
        public ?ReportFrequency $frequency = null,
        public ?ReportFormat $format = null,
        public ?string $startTime = null,
        public ?int $startDay = null,
        public ?string $timezone = null,
        public array $fields = [],
        public ?string $subscriptionType = null,
        public ?string $organizationId = null,
        public array $raw = [],
    ) {}
}
