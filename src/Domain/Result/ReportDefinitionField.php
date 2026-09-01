<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Command\CreateReportRequest;

/**
 * One selectable field on a report definition.
 *
 * The set of fields is a property of the definition, not of reporting as a whole — the columns a
 * transaction report offers are not the columns a chargeback report offers — which is why fields
 * are discovered per definition rather than fixed in the SDK. Feed the names into
 * {@see CreateReportRequest::$fields}.
 */
final readonly class ReportDefinitionField
{
    /**
     * @param  string|null  $name  Field name, as passed in a report's `reportFields`
     * @param  string|null  $id  Gateway identifier for the field
     * @param  string|null  $description  Human-readable description of what the column holds
     * @param  bool  $isRequired  Whether the definition always includes this field
     * @param  bool  $isDefault  Whether the field is included when no explicit field list is given
     * @param  string|null  $filterType  How the field may be filtered on, when it is filterable
     * @param  string|null  $supportedValues  Valid values for the filter, when the gateway declares them
     * @param  array<string, mixed>  $raw  Raw gateway attribute payload
     */
    public function __construct(
        public ?string $name = null,
        public ?string $id = null,
        public ?string $description = null,
        public bool $isRequired = false,
        public bool $isDefault = false,
        public ?string $filterType = null,
        public ?string $supportedValues = null,
        public array $raw = [],
    ) {}
}
