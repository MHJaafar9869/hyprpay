<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Hyprpay\Payments\Domain\Contract\PrunesPaymentActivity;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;

/**
 * Artisan command that applies a retention window to the payment store.
 *
 * The dashboard's attempts ledger grows for as long as payments keep happening, and with
 * API-response recording on, each row can carry a request/response pair — so an install that
 * never prunes will grow without bound. Schedule this daily.
 *
 * Only the database driver retains anything: the cache driver is a bounded ring buffer and
 * the null driver keeps nothing, so on those this reports that there was nothing to do rather
 * than pretending to have worked.
 */
final class PruneCommand extends Command
{
    protected $signature = 'hyprpay:prune
        {--hours=168 : Discard activity recorded more than this many hours ago}';

    protected $description = 'Prune payment activity older than the retention window from the dashboard store.';

    /**
     * Discard everything recorded before the cutoff and report what went.
     */
    public function handle(PrunesPaymentActivity $pruner, ConfigRepository $config): int
    {
        $hours = Value::int($this->option('hours'), 168);

        if ($hours < 1) {
            $this->components->error('--hours must be at least 1.');

            return self::FAILURE;
        }

        $driver = Value::string($config->get('gateway.dashboard.store.driver'), 'database');

        if ($driver !== 'database') {
            $this->components->info("The '{$driver}' store keeps no history to prune.");

            return self::SUCCESS;
        }

        $before = Carbon::now()->subHours($hours);
        $pruned = $pruner->prune($before);

        $this->components->info("Pruned {$pruned} rows recorded before {$before->toDayDateTimeString()}.");

        return self::SUCCESS;
    }
}
