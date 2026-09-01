<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\ReportDefinitionName;
use Hyprpay\Payments\Domain\Enum\ReportFormat;
use Hyprpay\Payments\Domain\Result\Report;

/**
 * Input DTO for creating a one-off (ad-hoc) report covering a fixed window.
 *
 * Unlike a report subscription, which schedules the same report repeatedly, this asks the
 * gateway to generate a single report over the given period. Generation is asynchronous:
 * the create call only queues the work, so the report has to be found with a list call and
 * downloaded once its {@see Report::$status} reports ready.
 *
 * {@see $definitionName} selects which report to run. The documented reports are typed as
 * {@see ReportDefinitionName} cases; a raw string is still accepted, because a merchant's actual
 * catalogue depends on their entitlements and may hold custom definitions the enum does not model
 * (discover it with `listReportDefinitions()`). {@see $name} is the label you will later find and
 * download the report by, so it should be unique enough to identify this run.
 */
final readonly class CreateReportRequest
{
    /**
     * @param  string  $name  Merchant-assigned name for this report run; the handle used to download it later
     * @param  ReportDefinitionName|string  $definitionName  Report definition to run — a {@see ReportDefinitionName} case for the documented reports, or a raw name for a custom one
     * @param  string  $startTime  Start of the reporting window, UTC ISO 8601 — `YYYY-MM-DD` or a full `YYYY-MM-DDTHH:mm:ss.SSSZ`
     * @param  string  $endTime  End of the reporting window, in the same format
     * @param  ReportFormat  $format  File format to generate the report in
     * @param  array<int, string>  $fields  Columns to include; empty uses the definition's default field set
     * @param  string|null  $timezone  Timezone the window is interpreted in (e.g. `GMT`); the gateway's default applies when omitted
     * @param  array<string, array<int, string>>  $filters  Report filters as field => allowed values (e.g. `['Application.Name' => ['ics_auth']]`)
     * @param  string|null  $groupName  Report group to file this report under, for merchants organised into groups
     * @param  string|null  $organizationId  Organization the report belongs to; defaults to the credentials' organization
     */
    public function __construct(
        public string $name,
        public ReportDefinitionName|string $definitionName,
        public string $startTime,
        public string $endTime,
        public ReportFormat $format = ReportFormat::Csv,
        public array $fields = [],
        public ?string $timezone = null,
        public array $filters = [],
        public ?string $groupName = null,
        public ?string $organizationId = null,
    ) {}
}
