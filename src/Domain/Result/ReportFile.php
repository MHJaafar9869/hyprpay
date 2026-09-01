<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\ReportFormat;

/**
 * Result DTO holding a downloaded report file.
 *
 * The gateway returns the report as a file body — CSV or XML — rather than JSON, so this
 * carries the raw content verbatim alongside the format and the name/date it was fetched
 * by. The content is not parsed: a report's columns depend on the definition and the fields
 * requested, so interpreting it is the caller's job.
 */
final readonly class ReportFile
{
    /**
     * @param  string  $content  The report file exactly as the gateway returned it
     * @param  ReportFormat  $format  Format the content is in
     * @param  string  $name  Name the report was downloaded by
     * @param  string  $reportDate  Report date the download was keyed on (`YYYY-MM-DD`)
     */
    public function __construct(
        public string $content,
        public ReportFormat $format,
        public string $name,
        public string $reportDate,
    ) {}

    /**
     * Whether the gateway returned an empty file — a report that ran but matched no rows.
     */
    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }

    /**
     * Size of the downloaded file in bytes.
     */
    public function bytes(): int
    {
        return strlen($this->content);
    }

    /**
     * A conventional filename for saving the report, combining its name, date, and format
     * extension (e.g. `daily-settlement-2026-09-01.csv`).
     */
    public function filename(): string
    {
        return sprintf('%s-%s.%s', $this->name, $this->reportDate, $this->format->extension());
    }
}
