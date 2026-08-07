<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs;

use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * A PayTabs repeat-billing agreement ({@see PaytabsCheckoutOptions::$agreement}).
 *
 * Once the customer completes the initial payment and consents, PayTabs tokenises the card
 * and auto-bills this schedule. The agreement currency and initial amount are taken from
 * the checkout money (PayTabs requires them to match), so only the schedule is described here.
 */
final readonly class PaytabsAgreement
{
    /**
     * @param  string|null  $description  Human-readable agreement description.
     * @param  int|string|null  $repeatAmount  Amount billed each cycle (number or decimal string).
     * @param  int|null  $repeatTerms  Total number of billing cycles.
     * @param  int|null  $repeatPeriod  Length of one cycle.
     * @param  int|null  $repeatEvery  How many periods between charges.
     * @param  string|null  $firstInstallmentDueDate  Date of the first scheduled charge.
     */
    public function __construct(
        public ?string $description = null,
        public int|string|null $repeatAmount = null,
        public ?int $repeatTerms = null,
        public ?int $repeatPeriod = null,
        public ?int $repeatEvery = null,
        public ?string $firstInstallmentDueDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $agreement
     */
    public static function fromArray(array $agreement): self
    {
        return new self(
            description: Value::nullableString($agreement['agreement_description'] ?? null),
            repeatAmount: is_int($agreement['repeat_amount'] ?? null) ? $agreement['repeat_amount'] : Value::nullableString($agreement['repeat_amount'] ?? null),
            repeatTerms: isset($agreement['repeat_terms']) ? Value::int($agreement['repeat_terms']) : null,
            repeatPeriod: isset($agreement['repeat_period']) ? Value::int($agreement['repeat_period']) : null,
            repeatEvery: isset($agreement['repeat_every']) ? Value::int($agreement['repeat_every']) : null,
            firstInstallmentDueDate: Value::nullableString($agreement['first_installment_due_date'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'agreement_description' => $this->description,
            'repeat_amount' => $this->repeatAmount,
            'repeat_terms' => $this->repeatTerms,
            'repeat_period' => $this->repeatPeriod,
            'repeat_every' => $this->repeatEvery,
            'first_installment_due_date' => $this->firstInstallmentDueDate,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
