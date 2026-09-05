<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard\Actions;

use DateTimeInterface;
use Hyprpay\Payments\Domain\Contract\PrunesPaymentActivity;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Applies a retention window to the durable payment store.
 *
 * The attempts ledger is the table that grows without bound — one row per payment event, and
 * each row can carry the operation's recorded gateway calls — so a long-running install needs
 * a cutoff. Prunes the three time-series tables by their own timestamps, then drops the
 * invoices whose last activity predates the cutoff; their child rows are already gone by that
 * point, and the foreign key nulls rather than cascades, so ordering is not load-bearing.
 *
 * Deliberately not wrapped in a transaction: a long retention sweep would hold locks across
 * every table, and the deletes are independent — a partial sweep simply prunes less.
 */
final readonly class PruneActivityInDatabase implements PrunesPaymentActivity
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

    public function prune(DateTimeInterface $before): int
    {
        $db = $this->connection();
        $cutoff = $before->format('c');

        $pruned = $db->table($this->table('payment_attempts'))->where('recorded_at', '<', $cutoff)->delete()
            + $db->table($this->table('webhooks'))->where('recorded_at', '<', $cutoff)->delete()
            + $db->table($this->table('payments'))->where('created_at', '<', $before)->delete();

        return $pruned + $db->table($this->table('invoices'))->where('last_activity_at', '<', $before)->delete();
    }

    /**
     * Prefix a bare table name with the store's configured prefix.
     */
    private function table(string $name): string
    {
        return $this->prefix.$name;
    }

    /**
     * Resolve the configured database connection (or the default when none is set).
     */
    private function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }
}
