<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * File format a gateway report is generated in.
 *
 * The backing values are the MIME types CyberSource uses both as the `reportMimeType`
 * request field and as the `Accept` header when the generated file is downloaded — the
 * download must ask for the format the report was created in.
 */
enum ReportFormat: string
{
    case Csv = 'text/csv';
    case Xml = 'application/xml';

    /**
     * Conventional file extension for the format, without the leading dot — for naming
     * a downloaded report on disk.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Csv => 'csv',
            self::Xml => 'xml',
        };
    }

    /**
     * Human-readable display name for the format.
     */
    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xml => 'XML',
        };
    }
}
