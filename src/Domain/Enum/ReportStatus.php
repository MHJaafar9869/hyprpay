<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Generation status of a gateway report.
 *
 * A report is requested, queued, generated, and only then downloadable — so a report id
 * existing does not mean a file exists behind it. Check {@see isReady()} before attempting
 * a download rather than treating every returned report as complete.
 */
enum ReportStatus: string
{
    case Completed = 'COMPLETED';
    case Pending = 'PENDING';
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Error = 'ERROR';
    case NoData = 'NO_DATA';

    /**
     * Human-readable display name for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Error => 'Error',
            self::NoData => 'No data',
        };
    }

    /**
     * Whether the report has finished generating and its file can be downloaded.
     *
     * True only for a completed report. Note that {@see self::NoData} is a *successful*
     * run that simply matched no rows — it is finished, but there is no file to fetch.
     */
    public function isReady(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether the report is still being produced, so a download would be premature —
     * poll until it settles rather than treating the absent file as an error.
     */
    public function isInProgress(): bool
    {
        return match ($this) {
            self::Pending, self::Queued, self::Running => true,
            self::Completed, self::Error, self::NoData => false,
        };
    }

    /**
     * Whether generation failed outright. A no-data report is deliberately not a failure:
     * it ran successfully and matched nothing.
     */
    public function isFailed(): bool
    {
        return $this === self::Error;
    }
}
