<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Command\CreateReportRequest;
use Hyprpay\Payments\Domain\Enum\ReportDefinitionName;
use Hyprpay\Payments\Domain\Enum\ReportFormat;
use Hyprpay\Payments\Domain\Enum\ReportSubscriptionType;

/**
 * Result DTO describing a report the merchant may run — the catalogue entry behind
 * {@see CreateReportRequest::$definitionName}.
 *
 * Which definitions exist, and which fields each one offers, depends on the merchant's
 * entitlements and subscription type, so this is discovered from the gateway rather than fixed
 * in the SDK. That is also why the definition name is a plain string on the request: freezing
 * the set would reject names a given merchant is legitimately entitled to.
 */
final readonly class ReportDefinition
{
    /**
     * @param  string|null  $name  Definition name, passed as a report's `reportDefinitionName`
     * @param  int|null  $id  Gateway identifier for the definition, usable as a list filter
     * @param  string|null  $description  Human-readable description of the report
     * @param  string|null  $type  The definition's reporting category
     * @param  ReportSubscriptionType|null  $subscriptionType  Which family the definition was resolved under
     * @param  list<ReportFormat>  $supportedFormats  File formats this report can be generated in
     * @param  list<ReportDefinitionField>  $fields  Selectable fields; empty on a catalogue listing, populated on a single lookup
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public ?string $name = null,
        public ?int $id = null,
        public ?string $description = null,
        public ?string $type = null,
        public ?ReportSubscriptionType $subscriptionType = null,
        public array $supportedFormats = [],
        public array $fields = [],
        public array $raw = [],
    ) {}

    /**
     * The names of every field this definition offers, ready to pass as a report's `reportFields`.
     *
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_values(array_filter(array_map(
            static fn (ReportDefinitionField $field): ?string => $field->name,
            $this->fields,
        )));
    }

    /**
     * The names of the fields the definition always includes, which a field list must carry.
     *
     * @return list<string>
     */
    public function requiredFieldNames(): array
    {
        return array_values(array_filter(array_map(
            static fn (ReportDefinitionField $field): ?string => $field->isRequired ? $field->name : null,
            $this->fields,
        )));
    }

    /**
     * This definition as a {@see ReportDefinitionName} case, or null when the gateway returned a
     * name the enum does not model — a custom or newly-published report, which is not an error.
     */
    public function definitionName(): ?ReportDefinitionName
    {
        return ReportDefinitionName::resolve($this->name);
    }

    /**
     * Whether this report can be generated in the given format.
     */
    public function supports(ReportFormat $format): bool
    {
        return in_array($format, $this->supportedFormats, true);
    }
}
