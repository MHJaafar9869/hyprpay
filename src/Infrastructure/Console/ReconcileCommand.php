<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Application\ReconciliationOutcome;
use Hyprpay\Payments\Application\TransactionReconciler;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Illuminate\Console\Command;

/**
 * Base Artisan command that reconciles a gateway's transactions by id.
 *
 * Each concrete subclass binds a single {@see GatewayName}; this base derives the
 * command name (`gateway:reconcile:{key}`) and description from it, fetches the
 * authoritative status for every id argument through {@see TransactionReconciler},
 * and renders the results as a table. The exit code is non-zero when any id could
 * not be reconciled (its gateway lookup failed), so the command is safe to wire
 * into monitoring or scheduled reconciliation.
 */
abstract class ReconcileCommand extends Command
{
    /**
     * The gateway this command reconciles.
     */
    abstract protected function gateway(): GatewayName;

    /**
     * Derive the command name and description from the bound gateway.
     */
    public function __construct()
    {
        $gateway = $this->gateway();

        $this->signature = sprintf(
            'gateway:reconcile:%s {transaction* : One or more gateway transaction ids to reconcile}',
            $gateway->value,
        );

        $this->description = sprintf(
            'Reconcile %s transactions by fetching their current status from the gateway.',
            $gateway->label(),
        );

        parent::__construct();
    }

    /**
     * Reconcile every transaction id argument and render the outcomes as a table.
     */
    public function handle(TransactionReconciler $reconciler): int
    {
        $transactionIds = array_values(array_map(
            static fn (mixed $id): string => Value::string($id),
            (array) $this->argument('transaction'),
        ));

        $outcomes = $reconciler->reconcile($this->gateway(), $transactionIds);

        $this->table(
            ['Transaction', 'Status', 'Amount', 'Order Ref'],
            array_map($this->toRow(...), $outcomes),
        );

        $reconciled = array_filter($outcomes, static fn (ReconciliationOutcome $outcome): bool => $outcome->reconciled());

        return count($reconciled) === count($outcomes) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Map a reconciliation outcome to a display row.
     *
     * @return array<int, string>
     */
    private function toRow(ReconciliationOutcome $outcome): array
    {
        $snapshot = $outcome->snapshot;

        if (! $snapshot instanceof TransactionSnapshot) {
            return [$outcome->transactionId, '<error>lookup failed</error>', $outcome->error ?? '', '—'];
        }

        return [
            $snapshot->transactionId,
            $snapshot->status->label(),
            $this->amount($snapshot),
            $snapshot->orderReference ?? '—',
        ];
    }

    /**
     * Render the snapshot amount as "100.00 EGP", or a dash when the amount is unknown.
     */
    private function amount(TransactionSnapshot $snapshot): string
    {
        $money = $snapshot->money;

        return $money instanceof Money ? $money->toDecimalString().' '.$money->currency : '—';
    }
}
