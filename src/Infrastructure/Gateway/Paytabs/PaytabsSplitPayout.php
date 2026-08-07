<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs;

use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * One split-payout stakeholder ({@see PaytabsCheckoutOptions::$splitPayout}).
 *
 * PayTabs distributes the settled funds across these entities after the customer pays;
 * each carries its share ({@see $itemTotal}), a marketplace-service-charge flag, and the
 * {@see PaytabsBeneficiary} bank details the funds are paid out to.
 */
final readonly class PaytabsSplitPayout
{
    /**
     * @param  int|string|null  $entityId  Stakeholder entity id.
     * @param  string|null  $entityName  Stakeholder name.
     * @param  string|null  $itemDescription  Description of the stakeholder's share.
     * @param  string|null  $itemTotal  The stakeholder's amount as a decimal string.
     * @param  string|null  $mscFlag  Marketplace service-charge flag.
     * @param  PaytabsBeneficiary|null  $beneficiary  Bank details the share is paid out to.
     */
    public function __construct(
        public int|string|null $entityId = null,
        public ?string $entityName = null,
        public ?string $itemDescription = null,
        public ?string $itemTotal = null,
        public ?string $mscFlag = null,
        public ?PaytabsBeneficiary $beneficiary = null,
    ) {}

    /**
     * @param  array<string, mixed>  $stakeholder
     */
    public static function fromArray(array $stakeholder): self
    {
        $beneficiary = Value::array($stakeholder['beneficiary'] ?? null);

        return new self(
            entityId: self::scalarId($stakeholder['entity_id'] ?? null),
            entityName: Value::nullableString($stakeholder['entity_name'] ?? null),
            itemDescription: Value::nullableString($stakeholder['item_description'] ?? null),
            itemTotal: Value::nullableString($stakeholder['item_total'] ?? null),
            mscFlag: Value::nullableString($stakeholder['msc_flag'] ?? null),
            beneficiary: $beneficiary === [] ? null : PaytabsBeneficiary::fromArray($beneficiary),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'entity_id' => $this->entityId,
            'entity_name' => $this->entityName,
            'item_description' => $this->itemDescription,
            'item_total' => $this->itemTotal,
            'msc_flag' => $this->mscFlag,
            'beneficiary' => $this->beneficiary?->toArray(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  mixed  $value
     */
    private static function scalarId($value): int|string|null
    {
        return is_int($value) ? $value : Value::nullableString($value);
    }
}
