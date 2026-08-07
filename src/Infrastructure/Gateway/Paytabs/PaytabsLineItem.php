<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs;

use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * One invoice line item ({@see PaytabsCheckoutOptions::$lineItems}).
 *
 * When no line items are supplied, the driver bills a single line for the full amount.
 */
final readonly class PaytabsLineItem
{
    /**
     * @param  string|null  $sku  Product SKU.
     * @param  string|null  $description  Line description.
     * @param  int|string|null  $unitCost  Unit cost as a number or decimal string.
     * @param  int|null  $quantity  Quantity of units.
     */
    public function __construct(
        public ?string $sku = null,
        public ?string $description = null,
        public int|string|null $unitCost = null,
        public ?int $quantity = null,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item): self
    {
        return new self(
            sku: Value::nullableString($item['sku'] ?? null),
            description: Value::nullableString($item['description'] ?? null),
            unitCost: is_int($item['unit_cost'] ?? null) ? $item['unit_cost'] : Value::nullableString($item['unit_cost'] ?? null),
            quantity: isset($item['quantity']) ? Value::int($item['quantity']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'sku' => $this->sku,
            'description' => $this->description,
            'unit_cost' => $this->unitCost,
            'quantity' => $this->quantity,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
