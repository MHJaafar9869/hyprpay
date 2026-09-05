<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Http\ApiResponse;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * A single PII-safe entry in the monitoring dashboard's activity feed.
 *
 * Normalises any payment domain event into the same flat shape — the operation, the
 * gateway, the normalised status/outcome, the correlation identifiers, and the amount —
 * so the dashboard can render every kind of activity uniformly. Like the audit log, its
 * own fields are non-sensitive metadata only, never card data.
 *
 * When API-response recording is switched on, the record additionally carries the gateway HTTP
 * calls the operation made. Those are the one part that holds a real gateway payload, and
 * they are masked by the Redactor before they ever reach a record, so credentials and
 * cardholder fields are replaced rather than stored. The list is empty unless the feature
 * is enabled, which keeps the default posture unchanged.
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
     * @param  list<ApiResponse>  $apiResponses  Redacted gateway HTTP calls this operation made, when recording is on.
     * @param  int|null  $sequence  Monotonic store position, used as the feed's polling cursor; null on stores that have none.
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
        public array $apiResponses = [],
        public ?int $sequence = null,
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
     * @param  list<ApiResponse>  $apiResponses  Redacted gateway HTTP calls this operation made.
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
        array $apiResponses = [],
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
            $apiResponses,
        );
    }

    /**
     * Serialise the record to a primitive array for cache storage.
     *
     * @return array<string, mixed>
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
            'apiResponses' => array_map(static fn (ApiResponse $e): array => $e->toArray(), $this->apiResponses),
            'sequence' => $this->sequence,
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
            apiResponses: self::apiResponses($data['apiResponses'] ?? null),
            sequence: is_numeric($data['sequence'] ?? null) ? (int) $data['sequence'] : null,
        );
    }

    /**
     * Rehydrate the recorded API responses from a stored row, skipping anything malformed.
     *
     * @param  mixed  $data  The stored response list, as decoded from JSON.
     * @return list<ApiResponse>
     */
    private static function apiResponses(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $apiResponses = [];

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $apiResponses[] = new ApiResponse(
                method: self::str($row['method'] ?? null) ?? '',
                url: self::str($row['url'] ?? null) ?? '',
                requestHeaders: self::headers($row['requestHeaders'] ?? null),
                requestBody: self::str($row['requestBody'] ?? null),
                status: is_numeric($row['status'] ?? null) ? (int) $row['status'] : 0,
                responseHeaders: self::headers($row['responseHeaders'] ?? null),
                responseBody: self::str($row['responseBody'] ?? null),
                durationMs: is_numeric($row['durationMs'] ?? null) ? (int) $row['durationMs'] : 0,
                recordedAt: self::str($row['recordedAt'] ?? null) ?? '',
            );
        }

        return $apiResponses;
    }

    /**
     * Narrow a stored header map to string keys and string values.
     *
     * @param  mixed  $data  The stored header map, as decoded from JSON.
     * @return array<string, string>
     */
    private static function headers(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $headers = [];

        foreach ($data as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
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
