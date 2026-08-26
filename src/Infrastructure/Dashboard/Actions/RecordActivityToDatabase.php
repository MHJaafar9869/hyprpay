<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard\Actions;

use Hyprpay\Payments\Domain\Contract\RecordsPaymentActivity;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Record action that persists durable, normalized payment history to the database store.
 *
 * Folds the record into its `invoices` row (the order, keyed by gateway + order reference —
 * current state, attempt count, paid status), appends it to the `payment_attempts` ledger
 * (the feed the dashboard reads), records a `payments` row for each successful outcome, and
 * captures webhook events in `webhooks`. Only PII-safe fields are written — never a card
 * number or a raw gateway payload.
 *
 * Best-effort: any database failure (a missing table, a dropped connection) is swallowed so
 * that capturing activity can never break the payment flow that produced it.
 */
final readonly class RecordActivityToDatabase implements RecordsPaymentActivity
{
    /**
     * @param  ConnectionResolverInterface  $resolver  Resolves the database connection the store lives on.
     * @param  string|null  $connection  Connection name to use, or null for the default connection.
     * @param  string  $prefix  Table-name prefix shared by every store table.
     */
    public function __construct(
        private ConnectionResolverInterface $resolver,
        private ?string $connection,
        private string $prefix,
    ) {}

    public function record(PaymentActivityRecord $record): void
    {
        try {
            $this->connection()->transaction(function (ConnectionInterface $db) use ($record): void {
                $now = Carbon::now();
                $successful = $record->status?->isSuccessful() === true;
                $invoiceId = $this->upsertInvoice($db, $record, $successful, $now);

                $db->table($this->table('payment_attempts'))->insert($this->attemptRow($record, $invoiceId, $now));

                if ($successful) {
                    $db->table($this->table('payments'))->insert($this->paymentRow($record, $invoiceId, $now));
                }

                if ($record->operation === 'WebhookReceived') {
                    $db->table($this->table('webhooks'))->insert($this->webhookRow($record, $now));
                }
            });
        } catch (Throwable) {
        }
    }

    /**
     * Fold a record into its invoice (by gateway + order reference), returning the invoice id.
     *
     * Records without an order reference are not grouped into an invoice.
     */
    private function upsertInvoice(ConnectionInterface $db, PaymentActivityRecord $record, bool $successful, Carbon $now): ?int
    {
        if (! filled($record->orderReference)) {
            return null;
        }

        $invoices = $db->table($this->table('invoices'));

        $existing = $invoices->where('gateway', $record->gateway->value)
            ->where('invoice_number', $record->orderReference)
            ->first();

        if ($existing !== null) {
            $current = (array) $existing;
            $paid = ($current['paid_status'] ?? null) === 'paid' || $successful;
            $status = $record->status?->value;

            $invoices->where('id', Value::int($current['id']))->update([
                'reference_number' => $record->reference ?? $current['reference_number'],
                'status' => $status ?? $current['status'],
                'paid_status' => $paid ? 'paid' : 'unpaid',
                'amount_minor' => $record->amountMinor ?? $current['amount_minor'],
                'currency' => $record->currency ?? $current['currency'],
                'scale' => $record->scale ?? $current['scale'],
                'attempts_count' => Value::int($current['attempts_count'] ?? 0) + 1,
                'last_activity_at' => $now,
                'updated_at' => $now,
            ]);

            return Value::int($current['id']);
        }

        return (int) $invoices->insertGetId([
            'uid' => $this->uuid(),
            'gateway' => $record->gateway->value,
            'invoice_number' => $record->orderReference,
            'reference_number' => $record->reference,
            'status' => $record->status?->value,
            'paid_status' => $successful ? 'paid' : 'unpaid',
            'amount_minor' => $record->amountMinor,
            'currency' => $record->currency,
            'scale' => $record->scale,
            'test_mode' => true,
            'attempts_count' => 1,
            'last_activity_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The column values for one appended attempt row (the dashboard's activity feed).
     *
     * @return array<string, mixed>
     */
    private function attemptRow(PaymentActivityRecord $record, ?int $invoiceId, Carbon $now): array
    {
        return [
            'invoice_id' => $invoiceId,
            'gateway' => $record->gateway->value,
            'operation' => $record->operation,
            'status' => $record->status?->value,
            'success' => $record->success,
            'order_reference' => $record->orderReference,
            'transaction_id' => $record->transactionId,
            'reference' => $record->reference,
            'amount_minor' => $record->amountMinor,
            'currency' => $record->currency,
            'scale' => $record->scale,
            'recorded_at' => $record->recordedAt,
            'created_at' => $now,
        ];
    }

    /**
     * The column values for one recorded (successful) payment.
     *
     * @return array<string, mixed>
     */
    private function paymentRow(PaymentActivityRecord $record, ?int $invoiceId, Carbon $now): array
    {
        return [
            'uid' => $this->uuid(),
            'invoice_id' => $invoiceId,
            'gateway' => $record->gateway->value,
            'method_type' => $record->operation,
            'transaction_reference' => $record->transactionId,
            'status' => $record->status?->value,
            'amount_minor' => $record->amountMinor,
            'currency' => $record->currency,
            'scale' => $record->scale,
            'paid_at' => $record->recordedAt,
            'created_at' => $now,
        ];
    }

    /**
     * The column values for one received-webhook row.
     *
     * @return array<string, mixed>
     */
    private function webhookRow(PaymentActivityRecord $record, Carbon $now): array
    {
        return [
            'gateway' => $record->gateway->value,
            'event_type' => $record->reference,
            'transaction_id' => $record->transactionId,
            'status' => $record->status?->value,
            'verified' => $record->success,
            'recorded_at' => $record->recordedAt,
            'created_at' => $now,
        ];
    }

    /**
     * Generate a random RFC-4122 v4 UUID without pulling in an external dependency.
     */
    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Resolve the configured database connection (or the default when none is set).
     */
    private function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }

    /**
     * Prefix a bare table name with the configured store prefix.
     */
    private function table(string $name): string
    {
        return $this->prefix.$name;
    }
}
