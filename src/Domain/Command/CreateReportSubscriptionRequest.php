<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\ReportDefinitionName;
use Hyprpay\Payments\Domain\Enum\ReportFormat;
use Hyprpay\Payments\Domain\Enum\ReportFrequency;

/**
 * Input DTO for scheduling a report the gateway generates on a recurring cadence.
 *
 * Where {@see CreateReportRequest} runs one report over a fixed window, a subscription makes
 * the gateway produce that report repeatedly and leave each run waiting to be downloaded.
 * The subscription is keyed by {@see $name}: creating one under a name that already exists
 * replaces it, so the same call both creates and updates.
 *
 * {@see $startTime} is the clock time of day the run begins, as `hhmm` (e.g. `0200`), not a
 * date. Weekly and monthly cadences additionally need {@see $startDay}, and a user-defined
 * cadence needs {@see $interval} — see {@see ReportFrequency::needsStartDay()} and
 * {@see ReportFrequency::needsInterval()}.
 */
final readonly class CreateReportSubscriptionRequest
{
    /**
     * @param  string  $name  Unique name for the subscription; re-using an existing name replaces that subscription
     * @param  ReportDefinitionName|string  $definitionName  Report definition to run — a {@see ReportDefinitionName} case, or a raw name for a custom definition
     * @param  array<int, string>  $fields  Columns to include in each generated report
     * @param  string  $startTime  Time of day each run starts, as `hhmm` (e.g. `0200` for 2am)
     * @param  ReportFrequency  $frequency  How often the report is generated
     * @param  ReportFormat  $format  File format each run is generated in
     * @param  string|null  $timezone  Timezone the schedule runs in (e.g. `GMT`)
     * @param  int|null  $startDay  Day the schedule starts on — 1-7 for weekly (1 is Sunday), 1-31 for monthly; ignored otherwise
     * @param  string|null  $interval  ISO 8601 duration between runs for a user-defined cadence (e.g. `PT2H30M`)
     * @param  array<string, array<int, string>>  $filters  Report filters as field => allowed values
     * @param  string|null  $groupName  Report group to file the subscription under
     * @param  string|null  $organizationId  Organization the subscription belongs to; defaults to the credentials' organization
     */
    public function __construct(
        public string $name,
        public ReportDefinitionName|string $definitionName,
        public array $fields,
        public string $startTime,
        public ReportFrequency $frequency = ReportFrequency::Daily,
        public ReportFormat $format = ReportFormat::Csv,
        public ?string $timezone = null,
        public ?int $startDay = null,
        public ?string $interval = null,
        public array $filters = [],
        public ?string $groupName = null,
        public ?string $organizationId = null,
    ) {}
}
