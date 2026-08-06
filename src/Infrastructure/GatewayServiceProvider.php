<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure;

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Contract\CredentialResolver;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Infrastructure\Console\ReconcileCybersourceCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcileFawryCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcilePaylinkCommand;
use Hyprpay\Payments\Infrastructure\Console\ReconcilePaymobCommand;
use Hyprpay\Payments\Infrastructure\Credentials\ConfigCredentialResolver;
use Hyprpay\Payments\Infrastructure\Http\LaravelHttpClient;
use Hyprpay\Payments\Infrastructure\Http\LoggingHttpClient;
use Hyprpay\Payments\Infrastructure\Http\RateLimitingHttpClient;
use Hyprpay\Payments\Infrastructure\Http\RetryingHttpClient;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Laravel service provider that wires the gateway SDK into the container.
 *
 * Merges the package config, binds the HttpClient and CredentialResolver ports
 * to their Laravel adapters, registers the PaymentGatewayFactory as a singleton,
 * and publishes the config file when running in the console.
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

        $this->app->singleton(PaymentGatewayFactory::class, static fn (Application $app): PaymentGatewayFactory => new PaymentGatewayFactory(
            $app->make(HttpClient::class),
            $app->make(CredentialResolver::class),
        ));
    }

    /**
     * Publish the package config and register the reconciliation commands
     * (when enabled) while running in the console.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../../config/gateway.php' => $this->app->configPath('gateway.php'),
        ], 'gateway-config');

        if (Value::bool($this->app->make(ConfigRepository::class)->get('gateway.commands.reconcile', true))) {
            $this->commands([
                ReconcileCybersourceCommand::class,
                ReconcileFawryCommand::class,
                ReconcilePaymobCommand::class,
                ReconcilePaylinkCommand::class,
            ]);
        }
    }
}
