<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure;

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Contract\CredentialResolver;
use Hyprpay\Payments\Domain\Contract\EventDispatcher;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Contract\PaymentActivityRepository;
use Hyprpay\Payments\Domain\Event\PaymentEvent;
use Hyprpay\Payments\Infrastructure\Console\ReconcileAirwallexCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcileAuthorizeNetCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcileCybersourceCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcileFawryCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcileMpgsCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcilePaylinkCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcilePaymobCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcilePayPalCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcilePaytabsCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcileTamaraCommand;
use Hyprpay\Payments\Infrastructure\Credentials\ConfigCredentialResolver;
use Hyprpay\Payments\Infrastructure\Dashboard\CachePaymentActivityRepository;
use Hyprpay\Payments\Infrastructure\Dashboard\NullPaymentActivityRepository;
use Hyprpay\Payments\Infrastructure\Events\LaravelEventDispatcher;
use Hyprpay\Payments\Infrastructure\Events\LoggingPaymentEventListener;
use Hyprpay\Payments\Infrastructure\Events\RecordingPaymentEventListener;
use Hyprpay\Payments\Infrastructure\Http\LaravelHttpClient;
use Hyprpay\Payments\Infrastructure\Http\LoggingHttpClient;
use Hyprpay\Payments\Infrastructure\Http\Middleware\AuthorizeDashboard;
use Hyprpay\Payments\Infrastructure\Http\RateLimitingHttpClient;
use Hyprpay\Payments\Infrastructure\Http\RetryingHttpClient;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Laravel service provider that wires the gateway SDK into the container.
 *
 * Merges the package config, registers a dedicated daily "hyprpay" log channel for the
 * SDK's own logs, binds the HttpClient, CredentialResolver, and EventDispatcher ports to
 * their Laravel adapters, registers the PaymentGatewayFactory as a singleton, registers the
 * audit-logging payment-event listener when enabled, and publishes the config file in the console.
 */
final class GatewayServiceProvider extends ServiceProvider
{
    /**
     * Register SDK bindings: merge config and bind the HTTP client, credential
     * resolver, and the gateway factory singleton into the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/gateway.php', 'gateway');

        $this->registerPackageLogChannel($this->app);

        $this->app->bind(HttpClient::class, static function (Application $app): HttpClient {
            $config = $app->make(ConfigRepository::class);

            $transport = new LaravelHttpClient(
                $app->make(HttpFactory::class),
                Value::int($config->get('gateway.http.timeout'), 30),
            );

            if (Value::bool($config->get('gateway.http.rate_limit'))) {
                $transport = new RateLimitingHttpClient(
                    $transport,
                    Value::int($config->get('gateway.http.rate_limit_max_requests'), 10),
                    Value::int($config->get('gateway.http.rate_limit_per_seconds'), 1),
                );
            }

            if (Value::bool($config->get('gateway.http.logging'))) {
                $transport = new LoggingHttpClient($transport, $app->make(LoggerInterface::class));
            }

            return new RetryingHttpClient(
                $transport,
                Value::int($config->get('gateway.http.retries'), 2) + 1,
                Value::int($config->get('gateway.http.retry_base_delay_ms'), 200),
                retryableExceptions: [ConnectionException::class],
            );
        });

        $this->app->bind(CredentialResolver::class, static fn (Application $app): CredentialResolver => new ConfigCredentialResolver(
            $app->make(ConfigRepository::class),
        ));

        $this->app->bind(EventDispatcher::class, static fn (Application $app): EventDispatcher => new LaravelEventDispatcher(
            $app->make(EventsDispatcher::class),
        ));

        $this->app->bind(PaymentActivityRepository::class, static function (Application $app): PaymentActivityRepository {
            $config = $app->make(ConfigRepository::class);

            if (! Value::bool($config->get('gateway.dashboard.store.enabled'))) {
                return new NullPaymentActivityRepository;
            }

            return new CachePaymentActivityRepository(
                $app->make(CacheFactory::class),
                Value::nullableString($config->get('gateway.dashboard.store.cache')),
                Value::string($config->get('gateway.dashboard.store.key'), 'hyprpay:activity'),
                Value::int($config->get('gateway.dashboard.store.limit'), 500),
            );
        });

        $this->app->singleton(PaymentGatewayFactory::class, static function (Application $app): PaymentGatewayFactory {
            $config = $app->make(ConfigRepository::class);

            return new PaymentGatewayFactory(
                $app->make(HttpClient::class),
                $app->make(CredentialResolver::class),
                Value::bool($config->get('gateway.events.enabled', true)) ? $app->make(EventDispatcher::class) : null,
                Value::bool($config->get('gateway.logging.operations')) ? self::packageLogger($app) : null,
            );
        });

        $this->app->when(LoggingPaymentEventListener::class)
            ->needs(LoggerInterface::class)
            ->give(static fn (Application $app): LoggerInterface => self::packageLogger($app));
    }

    /**
     * Register a dedicated daily "hyprpay" log channel (hyprpay-YYYY-MM-DD.log) unless the
     * host has already defined one, so the SDK's logs stay out of the default application log.
     */
    private function registerPackageLogChannel(Application $app): void
    {
        $config = $app->make(ConfigRepository::class);

        if (is_array($config->get('logging.channels.hyprpay'))) {
            return;
        }

        $config->set('logging.channels.hyprpay', [
            'driver' => 'daily',
            'path' => $app->storagePath('logs/hyprpay.log'),
            'days' => Value::int($config->get('gateway.logging.days'), 14),
            'level' => Value::string($config->get('gateway.logging.level'), 'debug'),
        ]);
    }

    /**
     * Resolve the PSR-3 logger the SDK writes to — the configured channel, or the
     * dedicated daily "hyprpay" channel.
     */
    private static function packageLogger(Application $app): LoggerInterface
    {
        $channel = $app->make(ConfigRepository::class)->get('gateway.logging.channel');
        $name = is_string($channel) && $channel !== '' ? $channel : 'hyprpay';

        return $app->make(LogManager::class)->channel($name);
    }

    /**
     * Register the payment-event listeners and mount the dashboard (when enabled), then —
     * in the console — publish the package assets and register the reconciliation commands.
     */
    public function boot(): void
    {
        $config = $this->app->make(ConfigRepository::class);

        if (Value::bool($config->get('gateway.events.log'))) {
            $this->app->make(EventsDispatcher::class)->listen(PaymentEvent::class, LoggingPaymentEventListener::class);
        }

        if (Value::bool($config->get('gateway.dashboard.store.enabled'))) {
            $this->app->make(EventsDispatcher::class)->listen(PaymentEvent::class, RecordingPaymentEventListener::class);
        }

        $this->bootDashboard($config);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/gateway.php' => $this->app->configPath('gateway.php'),
        ], 'gateway-config');

        $this->publishes([
            __DIR__.'/../../resources/views' => $this->app->resourcePath('views/vendor/hyprpay'),
        ], 'gateway-dashboard-views');

        if (Value::bool($config->get('gateway.commands.reconcile', true))) {
            $this->commands([
                ReconcileCybersourceCommand::class,
                ReconcileFawryCommand::class,
                ReconcilePaymobCommand::class,
                ReconcilePaylinkCommand::class,
                ReconcilePaytabsCommand::class,
                ReconcilePayPalCommand::class,
                ReconcileMpgsCommand::class,
                ReconcileAuthorizeNetCommand::class,
                ReconcileAirwallexCommand::class,
                ReconcileTamaraCommand::class,
            ]);
        }
    }

    /**
     * Mount the monitoring dashboard when enabled: its views, its authorization gate, and its routes.
     */
    private function bootDashboard(ConfigRepository $config): void
    {
        if (! Value::bool($config->get('gateway.dashboard.enabled'))) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'hyprpay');
        $this->registerDashboardGate();
        $this->registerDashboardRoutes($config);
    }

    /**
     * Define the default "viewHyprpay" gate (local-only) unless the host has already defined one.
     */
    private function registerDashboardGate(): void
    {
        if (Gate::has('viewHyprpay')) {
            return;
        }

        Gate::define('viewHyprpay', fn (?Authenticatable $user = null): bool => $this->app->environment('local') === true);
    }

    /**
     * Register the dashboard routes under the configured path, gated by AuthorizeDashboard and the host middleware.
     */
    private function registerDashboardRoutes(ConfigRepository $config): void
    {
        Route::group([
            'prefix' => Value::string($config->get('gateway.dashboard.path'), 'hyprpay'),
            'as' => 'hyprpay.dashboard.',
            'middleware' => [AuthorizeDashboard::class, ...$this->dashboardMiddleware($config)],
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/dashboard.php');
        });
    }

    /**
     * Resolve the host-configured middleware stack for the dashboard, defaulting to the web group.
     *
     * @return list<string>
     */
    private function dashboardMiddleware(ConfigRepository $config): array
    {
        $middleware = $config->get('gateway.dashboard.middleware', ['web']);

        return is_array($middleware)
            ? array_values(array_map(static fn (mixed $item): string => Value::string($item), $middleware))
            : ['web'];
    }
}
