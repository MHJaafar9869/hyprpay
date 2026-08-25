<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * A single PII-safe entry in the monitoring dashboard's activity feed.
 *
 * Normalises any payment domain event into the same flat shape — the operation, the
 * gateway, the normalised status/outcome, the correlation identifiers, and the amount —
 * so the dashboard can render every kind of activity uniformly. Like the audit log, it
 * carries only non-sensitive metadata: never the raw gateway payload or any card data.
 */
final readonly class PaymentActivityRecord
{
    /**
     * @param  string  $operation  The lifecycle operation, e.g. "PaymentCharged" (the event's short class name).
     * @param  GatewayName  $gateway  The gateway that produced the activity.
     * @param  PaymentStatus|null  $status  Normalised outcome status, when the operation reports one.
     * @param  bool|null  $success  Whether the operation succeeded, when known.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  string|null  $transactionId  Primary resulting identifier (transaction, refund, or vault id).
     * @param  string|null  $reference  Secondary correlation id (the authorization, instrument, customer, wallet, or event type).
     * @param  int|null  $amountMinor  Amount in minor units, when the operation carries one.
     * @param  string|null  $currency  ISO 4217 currency code for the amount, when present.
     * @param  int|null  $scale  Decimal scale of the amount, used to render it exactly.
     * @param  string  $recordedAt  ISO-8601 timestamp of when the record was captured.
     */
    public function __construct(
        public string $operation,
        public GatewayName $gateway,
        public ?PaymentStatus $status,
        public ?bool $success,
        public ?string $orderReference,
        public ?string $transactionId,
        public ?string $reference,
        public ?int $amountMinor,
        public ?string $currency,
        public ?int $scale,
        public string $recordedAt,
    ) {}

    /**
     * Build a record from an event's fields, splitting the amount from its Money value.
     *
     * @param  string  $operation  The lifecycle operation (event short class name).
     * @param  GatewayName  $gateway  The gateway that produced the activity.
     * @param  PaymentStatus|null  $status  Normalised outcome status, when reported.
     * @param  bool|null  $success  Whether the operation succeeded, when known.
     * @param  string|null  $orderReference  Merchant order reference.
     * @param  string|null  $transactionId  Primary resulting identifier.
     * @param  string|null  $reference  Secondary correlation identifier.
     * @param  Money|null  $money  The operation's amount, when it carries one.
     * @param  string  $recordedAt  ISO-8601 capture timestamp.
     */
    public static function make(
        string $operation,
        GatewayName $gateway,
        ?PaymentStatus $status,
        ?bool $success,
        ?string $orderReference,
        ?string $transactionId,
        ?string $reference,
        ?Money $money,
        string $recordedAt,
    ): self {
        return new self(
            $operation,
            $gateway,
            $status,
            $success,
            $orderReference,
            $transactionId,
            $reference,
            $money?->minorAmount,
            $money?->currency,
            $money?->scale,
            $recordedAt,
        );
    }

    /**
     * Serialise the record to a primitive array for cache storage.
     *
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'gateway' => $this->gateway->value,
            'status' => $this->status?->value,
            'success' => $this->success,
            'orderReference' => $this->orderReference,
            'transactionId' => $this->transactionId,
            'reference' => $this->reference,
            'amountMinor' => $this->amountMinor,
            'currency' => $this->currency,
            'scale' => $this->scale,
            'recordedAt' => $this->recordedAt,
        ];
    }

    /**
     * Rehydrate a record from its stored primitive array.
     *
     * Malformed rows (unknown gateway) yield null so the caller can skip them rather than fail.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $gateway = GatewayName::tryFrom(self::str($data['gateway'] ?? null) ?? '');

        if (! $gateway instanceof GatewayName) {
            return null;
        }

        return new self(
            operation: self::str($data['operation'] ?? null) ?? 'Unknown',
            gateway: $gateway,
            status: PaymentStatus::tryFrom(self::str($data['status'] ?? null) ?? ''),
            success: is_bool($data['success'] ?? null) ? $data['success'] : null,
            orderReference: self::str($data['orderReference'] ?? null),
            transactionId: self::str($data['transactionId'] ?? null),
            reference: self::str($data['reference'] ?? null),
            amountMinor: is_numeric($data['amountMinor'] ?? null) ? (int) $data['amountMinor'] : null,
            currency: self::str($data['currency'] ?? null),
            scale: is_numeric($data['scale'] ?? null) ? (int) $data['scale'] : null,
            recordedAt: self::str($data['recordedAt'] ?? null) ?? '',
        );
    }

    /**
     * Narrow a mixed value to a non-empty string, or null.
     *
     * @param  mixed  $value
     */
    private static function str($value): ?string
    {
        return filled($value) && is_scalar($value) ? (string) $value : null;
    }
}
