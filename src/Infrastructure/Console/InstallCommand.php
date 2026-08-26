<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Console;

use Illuminate\Console\Command;

/**
 * Artisan command that installs the package for a host application.
 *
 * Publishes the config file and the dashboard views, and — with `--migrate` — runs the
 * payment-store migrations (payments, their attempts, and webhooks) so the default
 * database store is ready. Idempotent: re-runnable, with `--force` to overwrite files.
 */
final class InstallCommand extends Command
{
    protected $signature = 'hyprpay:install
        {--migrate : Run the payment-store migrations after publishing}
        {--force : Overwrite any published files that already exist}';

    protected $description = 'Publish the Hyprpay config and dashboard views, and optionally run the payment-store migrations.';

    /**
     * Publish the package assets and, when asked, migrate the payment store.
     */
    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->components->info('Publishing Hyprpay config and dashboard views…');
        $this->callSilently('vendor:publish', ['--tag' => 'gateway-config', '--force' => $force]);
        $this->callSilently('vendor:publish', ['--tag' => 'gateway-dashboard-views', '--force' => $force]);

        if ($this->option('migrate')) {
            $this->components->info('Running the payment-store migrations…');
            $this->call('migrate', [
                '--path' => dirname(__DIR__, 3).'/database/migrations',
                '--realpath' => true,
                '--force' => true,
            ]);
        }

        $this->newLine();
        $this->components->info('Hyprpay is installed.');
        $this->line('  Enable recording:  <fg=yellow>GATEWAY_DASHBOARD_STORE=true</>');
        $this->line('  Store driver:      <fg=yellow>GATEWAY_DASHBOARD_STORE_DRIVER=database</> (default; use cache or null to override)');
        $this->line('  Create the tables: <fg=yellow>php artisan hyprpay:install --migrate</>');

        return self::SUCCESS;
    }
}
