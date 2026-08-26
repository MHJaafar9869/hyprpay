<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Dashboard;

use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Contract\ReadsLog;
use Hyprpay\Payments\Domain\Contract\ReadsPaymentActivity;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Exception\GatewayException;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Presenter that assembles the read-model the monitoring dashboard renders.
 *
 * Keeps the controller thin: it derives each gateway's health straight from configuration,
 * aggregates the recorded activity into headline stats, shapes the recent-activity feed for
 * display, and runs the on-demand by-reference lookup against a gateway. Every value it
 * returns is display-ready and PII-safe.
 */
final readonly class DashboardData
{
    /**
     * @param  ConfigRepository  $config  Source of gateway configuration (credentials presence, mode, default).
     * @param  ReadsPaymentActivity  $activity  The read seam the activity feed and stats are read from.
     * @param  ReadsLog  $log  The read seam the log panel reads recent SDK log entries from.
     */
    public function __construct(
        private ConfigRepository $config,
        private ReadsPaymentActivity $activity,
        private ReadsLog $log,
    ) {}

    /**
     * Build the full view model for the dashboard index page.
     *
     * @return array{gateways: list<array{key: string, label: string, configured: bool, testMode: bool, default: bool}>, stats: array{total: int, successful: int, successRate: int, byStatus: list<array{label: string, count: int}>, byGateway: list<array{label: string, count: int}>}, recent: list<array<string, mixed>>, gatewayOptions: list<array{value: string, label: string}>}
     */
    public function overview(int $limit): array
    {
        $records = $this->activity->recent($limit);

        return [
            'gateways' => $this->gatewayHealth(),
            'stats' => $this->stats($records),
            'recent' => array_map($this->present(...), $records),
            'gatewayOptions' => array_map(
                static fn (GatewayName $gateway): array => ['value' => $gateway->value, 'label' => $gateway->label()],
                GatewayName::cases(),
            ),
        ];
    }

    /**
     * Shape the most recent records for the activity feed (JSON/polling endpoint).
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(int $limit): array
    {
        return array_map($this->present(...), $this->activity->recent($limit));
    }

    /**
     * Read recent SDK log entries for the dashboard's log panel — newest first, each tagged
     * with a display tone by level.
     *
     * @return list<array{time: string, level: string, tone: string, message: string, detail: string}>
     */
    public function logs(int $limit): array
    {
        return array_map(fn (array $entry): array => [
            'time' => $entry['time'],
            'level' => $entry['level'],
            'tone' => $this->logTone($entry['level']),
            'message' => $entry['message'],
            'detail' => $entry['detail'],
        ], $this->log->recent($limit));
    }

    /**
     * Build one payment reference's full recorded lifecycle: an ordered event timeline
     * (oldest first) plus a rolled-up summary. Every value is display-ready and PII-safe.
     *
     * @return array{reference: string, found: bool, summary: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function lifecycle(string $reference): array
    {
        $records = $this->activity->lifecycle($reference);

        return [
            'reference' => $reference,
            'found' => $records !== [],
            'summary' => $this->lifecycleSummary($records),
            'events' => array_map($this->present(...), $records),
        ];
    }

    /**
     * Look up a payment's full history at the gateway by its merchant reference.
     *
     * Degrades gracefully when the gateway has not implemented listing.
     *
     * @return array{supported: bool, message: string|null, transactions: list<array<string, mixed>>}
     */
    public function lookup(PaymentGatewayInterface $gateway, string $reference): array
    {
        try {
            $transactions = array_map(
                $this->presentSnapshot(...),
                $gateway->listTransactionsByReference($reference),
            );

            return ['supported' => true, 'message' => null, 'transactions' => array_values($transactions)];
        } catch (GatewayException $gatewayException) {
            return ['supported' => false, 'message' => $gatewayException->getMessage(), 'transactions' => []];
        }
    }

    /**
     * Derive each gateway's configuration health from config.
     *
     * @return list<array{key: string, label: string, configured: bool, testMode: bool, default: bool}>
     */
    private function gatewayHealth(): array
    {
        $default = Value::string($this->config->get('gateway.default'));

        return array_map(function (GatewayName $gateway) use ($default): array {
            $settings = Value::array($this->config->get("gateway.gateways.{$gateway->value}"));

            return [
                'key' => $gateway->value,
                'label' => $gateway->label(),
                'configured' => filled($settings['shared_secret'] ?? null),
                'testMode' => Value::bool($settings['test_mode'] ?? true),
                'default' => $gateway->value === $default,
            ];
        }, GatewayName::cases());
    }

    /**
     * Aggregate recorded activity into headline stats.
     *
     * @param  list<PaymentActivityRecord>  $records
     * @return array{total: int, successful: int, successRate: int, byStatus: list<array{label: string, count: int}>, byGateway: list<array{label: string, count: int}>}
     */
    private function stats(array $records): array
    {
        $total = count($records);
        $successful = count(array_filter($records, static fn (PaymentActivityRecord $r): bool => $r->status?->isSuccessful() === true));

        return [
            'total' => $total,
            'successful' => $successful,
            'successRate' => $total === 0 ? 0 : (int) round($successful / $total * 100),
            'byStatus' => $this->tally(array_map(static fn (PaymentActivityRecord $r): string => $r->status?->label() ?? 'Unknown', $records)),
            'byGateway' => $this->tally(array_map(static fn (PaymentActivityRecord $r): string => $r->gateway->label(), $records)),
        ];
    }

    /**
     * Count occurrences of each label, ordered by frequency (highest first).
     *
     * @param  list<string>  $labels
     * @return list<array{label: string, count: int}>
     */
    private function tally(array $labels): array
    {
        $counts = array_count_values($labels);
        arsort($counts);

        return array_map(
            static fn (string $label, int $count): array => ['label' => $label, 'count' => $count],
            array_keys($counts),
            array_values($counts),
        );
    }

    /**
     * Shape a single activity record for display.
     *
     * @return array<string, mixed>
     */
    private function present(PaymentActivityRecord $record): array
    {
        return [
            'operation' => $record->operation,
            'gateway' => $record->gateway->label(),
            'status' => $record->status?->label(),
            'statusKey' => $record->status?->value,
            'tone' => $this->tone($record->status),
            'success' => $record->success,
            'orderReference' => $record->orderReference,
            'transactionId' => $record->transactionId,
            'reference' => $record->reference,
            'amount' => $this->formatAmount($record->amountMinor, $record->currency, $record->scale),
            'recordedAt' => $record->recordedAt,
        ];
    }

    /**
     * Shape a gateway TransactionSnapshot for the lookup panel.
     *
     * @return array<string, mixed>
     */
    private function presentSnapshot(TransactionSnapshot $snapshot): array
    {
        return [
            'transactionId' => $snapshot->transactionId,
            'status' => $snapshot->status->label(),
            'statusKey' => $snapshot->status->value,
            'tone' => $this->tone($snapshot->status),
            'orderReference' => $snapshot->orderReference,
            'amount' => $this->formatMoney($snapshot->money),
        ];
    }

    /**
     * Roll a lifecycle's records up into a display summary (latest state, totals, span).
     *
     * @param  list<PaymentActivityRecord>  $records
     * @return array<string, mixed>
     */
    private function lifecycleSummary(array $records): array
    {
        if ($records === []) {
            return ['gateway' => null, 'gatewayKey' => null, 'status' => null, 'tone' => 'none', 'amount' => null, 'attempts' => 0, 'successful' => 0, 'firstAt' => null, 'lastAt' => null];
        }

        $latest = $records[count($records) - 1];
        $successful = count(array_filter($records, static fn (PaymentActivityRecord $r): bool => $r->status?->isSuccessful() === true));

        return [
            'gateway' => $latest->gateway->label(),
            'gatewayKey' => $latest->gateway->value,
            'status' => $latest->status?->label(),
            'tone' => $this->tone($latest->status),
            'amount' => $this->latestAmount($records),
            'attempts' => count($records),
            'successful' => $successful,
            'firstAt' => $records[0]->recordedAt,
            'lastAt' => $latest->recordedAt,
        ];
    }

    /**
     * The most recent non-null amount across a lifecycle, formatted, or null.
     *
     * @param  list<PaymentActivityRecord>  $records
     */
    private function latestAmount(array $records): ?string
    {
        foreach (array_reverse($records) as $record) {
            $amount = $this->formatAmount($record->amountMinor, $record->currency, $record->scale);

            if ($amount !== null) {
                return $amount;
            }
        }

        return null;
    }

    /**
     * Map a log level to a display tone the view colours by: bad, warn, ok, or none.
     */
    private function logTone(string $level): string
    {
        return match (strtoupper($level)) {
            'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => 'bad',
            'WARNING' => 'warn',
            'NOTICE', 'INFO' => 'ok',
            default => 'none',
        };
    }

    /**
     * Map a payment status to a display tone the view colours by: ok, warn, bad, or none.
     */
    private function tone(?PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Captured, PaymentStatus::Authorized, PaymentStatus::Refunded => 'ok',
            PaymentStatus::Pending, PaymentStatus::Voided, PaymentStatus::Reversed => 'warn',
            PaymentStatus::Declined, PaymentStatus::Failed => 'bad',
            null => 'none',
        };
    }

    /**
     * Render a minor-unit amount as an exact decimal with its currency, or null.
     */
    private function formatAmount(?int $minorAmount, ?string $currency, ?int $scale): ?string
    {
        if ($minorAmount === null || $currency === null) {
            return null;
        }

        return $this->formatMoney(new Money($minorAmount, $currency, $scale ?? 2));
    }

    /**
     * Render a Money value as "amount CUR", or null when absent.
     */
    private function formatMoney(?Money $money): ?string
    {
        return $money instanceof Money ? "{$money->toDecimalString()} {$money->currency}" : null;
    }
}
