<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\ReportFormat;
use Hyprpay\Payments\Domain\Enum\ReportFrequency;
use Hyprpay\Payments\Domain\Enum\ReportStatus;

/**
 * Input DTO for finding the reports available over a period.
 *
 * The window is required — the gateway will not list reports without one — and every other
 * filter narrows the result. This is how an ad-hoc report created earlier is located: search
 * the window by name, then read the report id and status off the match.
 *
 * {@see $timeQueryType} decides which timestamp the window applies to: `executedTime` (when
 * the report ran) or `reportTimeFrame` (the period the report covers). They differ for any
 * report generated after the data it describes, which is most of them.
 */
final readonly class ListReportsRequest
{
    public const TIME_EXECUTED = 'executedTime';

    public const TIME_REPORT_FRAME = 'reportTimeFrame';

    /**
     * @param  string  $startTime  Start of the search window, UTC ISO 8601 — `YYYY-MM-DD` or a full `YYYY-MM-DDTHH:mm:ss.SSSZ`
     * @param  string  $endTime  End of the search window, in the same format
     * @param  string  $timeQueryType  Which timestamp the window filters on: `executedTime` or `reportTimeFrame`
     * @param  ReportStatus|null  $status  Return only reports in this generation state
     * @param  ReportFrequency|null  $frequency  Return only reports produced at this cadence
     * @param  ReportFormat|null  $format  Return only reports generated in this file format
     * @param  string|null  $name  Return only reports carrying this report name
     * @param  int|null  $definitionId  Return only reports produced from this report definition
     * @param  string|null  $organizationId  Organization to search; defaults to the credentials' organization
     */
    public function __construct(
        public string $startTime,
        public string $endTime,
        public string $timeQueryType = self::TIME_EXECUTED,
        public ?ReportStatus $status = null,
        public ?ReportFrequency $frequency = null,
        public ?ReportFormat $format = null,
        public ?string $name = null,
        public ?int $definitionId = null,
        public ?string $organizationId = null,
    ) {}
}
