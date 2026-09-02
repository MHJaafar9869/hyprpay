<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\ReportFormat;

/**
 * Input DTO for downloading a generated report file.
 *
 * A report is downloaded by name and date rather than by id. The date is the **end** of the
 * period the report covers, in the report subscription's timezone — for a report running
 * midnight to midnight on the 9th, that is the 10th, not the 9th. Getting this wrong is the
 * usual cause of a 404 on a report that plainly exists.
 *
 * {@see $format} must match the format the report was generated in: it is sent as the Accept
 * header, and asking for a format the report was not produced in fails rather than converting.
 */
final readonly class DownloadReportRequest
{
    /**
     * @param  string  $name  Name of the report to download
     * @param  string  $reportDate  Report date as `YYYY-MM-DD` — the end date of the period covered, in the report's timezone
     * @param  ReportFormat  $format  Format the report was generated in; sent as the Accept header
     * @param  string|null  $organizationId  Organization the report belongs to; defaults to the credentials' organization
     */
    public function __construct(
        public string $name,
        public string $reportDate,
        public ReportFormat $format = ReportFormat::Csv,
        public ?string $organizationId = null,
    ) {}
}
