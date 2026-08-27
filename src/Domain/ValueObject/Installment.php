<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

use Hyprpay\Payments\Domain\Command\ChargeRequest;

/**
 * Value object describing an issuer-funded installment plan for a charge.
 *
 * Attached to a {@see ChargeRequest} to bill the cardholder in installments the *issuer*
 * funds and splits (common across MENA, LATAM, and Turkey), as opposed to the merchant
 * splitting the amount into separate stored-credential charges. It maps to the CyberSource
 * `processingInformation.installment` block: the total number of installments, optionally
 * which installment this authorization is (for a later part), the processor's plan type, and
 * a grace period. Only the fields supplied are sent.
 */
final readonly class Installment
{
    /**
     * @param  int  $totalCount  Total number of installments the plan is split into
     * @param  int|null  $sequence  This installment's number within the plan (1-based), for a subsequent part
     * @param  string|null  $planType  Processor-specific installment plan type (e.g. issuer or merchant funded)
     * @param  int|null  $gracePeriodDuration  Grace period before the first installment, in the processor's unit (usually months)
     */
    public function __construct(
        public int $totalCount,
        public ?int $sequence = null,
        public ?string $planType = null,
        public ?int $gracePeriodDuration = null,
    ) {}

    /**
     * The CyberSource `processingInformation.installment` fields, omitting any not supplied.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'totalCount' => $this->totalCount,
            'sequence' => $this->sequence,
            'planType' => $this->planType,
            'gracePeriodDuration' => $this->gracePeriodDuration,
        ], static fn (int|string|null $value): bool => $value !== null);
    }
}
