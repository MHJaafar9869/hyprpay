<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs;

use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * A split-payout beneficiary's bank details ({@see PaytabsSplitPayout::$beneficiary}).
 */
final readonly class PaytabsBeneficiary
{
    /**
     * @param  string|null  $name  Account holder name.
     * @param  string|null  $accountNumber  Beneficiary account number / IBAN.
     * @param  string|null  $country  Beneficiary country (ISO code).
     * @param  string|null  $bank  Beneficiary bank name.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $accountNumber = null,
        public ?string $country = null,
        public ?string $bank = null,
    ) {}

    /**
     * @param  array<string, mixed>  $beneficiary
     */
    public static function fromArray(array $beneficiary): self
    {
        return new self(
            name: Value::nullableString($beneficiary['name'] ?? null),
            accountNumber: Value::nullableString($beneficiary['account_number'] ?? null),
            country: Value::nullableString($beneficiary['country'] ?? null),
            bank: Value::nullableString($beneficiary['bank'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'account_number' => $this->accountNumber,
            'country' => $this->country,
            'bank' => $this->bank,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
