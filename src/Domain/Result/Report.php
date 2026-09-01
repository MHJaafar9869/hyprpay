<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Command\DownloadReportRequest;
use Hyprpay\Payments\Domain\Enum\ReportFormat;
use Hyprpay\Payments\Domain\Enum\ReportFrequency;
use Hyprpay\Payments\Domain\Enum\ReportStatus;

/**
 * Result DTO describing one generated (or still generating) gateway report.
 *
 * Report generation is asynchronous, so a report existing is not the same as its file
 * existing: check {@see $status} before downloading. The download is keyed by name and
 * date rather than by {@see $reportId}, which is why {@see downloadRequest()} exists —
 * it derives the right {@see DownloadReportRequest} from this record so the report-date
 * rule does not have to be applied by hand.
 */
final readonly class Report
{
    /**
     * @param  string|null  $reportId  Gateway identifier for this report run
     * @param  string|null  $name  Report name, as assigned when the report or subscription was created
     * @param  string|null  $definitionId  Identifier of the report definition this run was produced from
     * @param  ReportStatus|null  $status  Normalised generation status, when the gateway reported one
     * @param  ReportFrequency|null  $frequency  Cadence this report was produced at
     * @param  ReportFormat|null  $format  File format the report was generated in
     * @param  string|null  $startTime  Start of the period the report covers (UTC ISO 8601)
     * @param  string|null  $endTime  End of the period the report covers (UTC ISO 8601)
     * @param  string|null  $completedTime  When generation finished (UTC ISO 8601), when it has
     * @param  string|null  $timezone  Timezone the report's times are expressed in
     * @param  string|null  $organizationId  Organization the report belongs to
     * @param  string|null  $subscriptionType  Whether the originating subscription is Custom, Standard, or Classic
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public ?string $reportId = null,
        public ?string $name = null,
        public ?string $definitionId = null,
        public ?ReportStatus $status = null,
        public ?ReportFrequency $frequency = null,
        public ?ReportFormat $format = null,
        public ?string $startTime = null,
        public ?string $endTime = null,
        public ?string $completedTime = null,
        public ?string $timezone = null,
        public ?string $organizationId = null,
        public ?string $subscriptionType = null,
        public array $raw = [],
    ) {}

    /**
     * Whether this report has finished generating and its file can be fetched.
     *
     * A report with no status at all is not assumed ready — the download would 404.
     */
    public function isDownloadable(): bool
    {
        return $this->status?->isReady() === true;
    }

    /**
     * Build the download request for this report, or null when it is not downloadable.
     *
     * Applies the report-date rule so the caller does not have to: the download is keyed by
     * the **end** of the period covered, taken from {@see $endTime} (falling back to
     * {@see $completedTime}), truncated to its `YYYY-MM-DD` date part. The format defaults
     * to the one the report was generated in, since asking for another fails.
     */
    public function downloadRequest(): ?DownloadReportRequest
    {
        $date = $this->endTime ?? $this->completedTime;

        if (! $this->isDownloadable() || $this->name === null || $date === null) {
            return null;
        }

        return new DownloadReportRequest(
            name: $this->name,
            reportDate: substr($date, 0, 10),
            format: $this->format ?? ReportFormat::Csv,
            organizationId: $this->organizationId,
        );
    }
}
