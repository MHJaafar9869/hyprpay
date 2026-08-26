<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard\Queries;

use Hyprpay\Payments\Domain\Contract\ReadsPaymentActivity;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Infrastructure\Dashboard\Actions\RecordActivityToDatabase;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * Reads payment activity from the durable database store written by
 * {@see RecordActivityToDatabase} — the recent feed (newest first) and a single payment's
 * full lifecycle (oldest first).
 *
 * Degrades to an empty result on any database failure so a missing table or a dropped
 * connection never breaks the dashboard.
 */
final readonly class RecentActivityFromDatabase implements ReadsPaymentActivity
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

    public function recent(int $limit): array
    {
        try {
            $rows = $this->connection()->table($this->prefix.'payment_attempts')
                ->orderByDesc('id')
                ->limit(max(0, $limit))
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (object $row): ?PaymentActivityRecord => PaymentActivityRecord::fromArray($this->toArray($row)),
            $rows,
        )));
    }

    public function lifecycle(string $reference): array
    {
        try {
            $rows = $this->connection()->table($this->prefix.'payment_attempts')
                ->where('order_reference', $reference)
                ->orWhere('transaction_id', $reference)
                ->orderBy('id')
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (object $row): ?PaymentActivityRecord => PaymentActivityRecord::fromArray($this->toArray($row)),
            $rows,
        )));
    }

    /**
     * Map a stored attempt row back to the primitive array {@see PaymentActivityRecord::fromArray()} reads.
     *
     * @return array<string, mixed>
     */
    private function toArray(object $row): array
    {
        $data = (array) $row;
        $success = $data['success'] ?? null;

        return [
            'operation' => $data['operation'] ?? null,
            'gateway' => $data['gateway'] ?? null,
            'status' => $data['status'] ?? null,
            'success' => $success === null ? null : (bool) $success,
            'orderReference' => $data['order_reference'] ?? null,
            'transactionId' => $data['transaction_id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'amountMinor' => $data['amount_minor'] ?? null,
            'currency' => $data['currency'] ?? null,
            'scale' => $data['scale'] ?? null,
            'recordedAt' => $data['recorded_at'] ?? null,
        ];
    }

    /**
     * Resolve the configured database connection (or the default when none is set).
     */
    private function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }
}
