<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Application;

use Hyprpay\Payments\Domain\Contract\CredentialResolver;
use Hyprpay\Payments\Domain\Contract\EventDispatcher;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Exception\GatewayNotSupportedException;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Gateway\EventDispatchingGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawryGateway;
use Hyprpay\Payments\Infrastructure\Gateway\LoggingGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\MpgsGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Paylink\PaylinkGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobGateway;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\PayPalGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsGateway;
use Psr\Log\LoggerInterface;

/**
 * Composition-edge factory that constructs concrete gateway drivers.
 *
 * Selects the correct adapter for a GatewayName via a match expression, wiring
 * it with the shared HttpClient and the credentials for that gateway (resolved
 * through the CredentialResolver when none are provided by the caller). Each driver is
 * then optionally wrapped: in an EventDispatchingGateway when an EventDispatcher is
 * supplied (so it emits payment domain events), and in a LoggingGateway when a logger is
 * supplied (so each operation is logged with its duration). Without either, the bare
 * driver is returned.
 */
final readonly class PaymentGatewayFactory
{
    /**
     * @param  HttpClient  $http  The outbound-HTTP client shared with every constructed driver.
     * @param  CredentialResolver  $credentialResolver  Resolves credentials when a caller does not pass them explicitly.
     * @param  EventDispatcher|null  $events  Dispatcher every driver is wrapped to emit events through, or null to skip event dispatch.
     * @param  LoggerInterface|null  $logger  Logger every driver is wrapped to log operations through, or null to skip operation logging.
     */
    public function __construct(
        private HttpClient $http,
        private CredentialResolver $credentialResolver,
        private ?EventDispatcher $events = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Build a driver for the given gateway. When no credentials are supplied,
     * they are resolved through the configured CredentialResolver (fallback).
     *
     * @param  GatewayName  $gateway  The gateway to instantiate a driver for.
     * @param  GatewayCredentials|null  $credentials  Explicit credentials, or null to resolve them from configuration.
     * @return PaymentGatewayInterface The concrete gateway driver.
     */
    public function make(GatewayName $gateway, ?GatewayCredentials $credentials = null): PaymentGatewayInterface
    {
        $resolved = $credentials ?? $this->credentialResolver->resolve($gateway);

        $driver = match ($gateway) {
            GatewayName::CybersourceUnifiedCheckout => new CybersourceUnifiedCheckoutGateway($resolved, $this->http),
            GatewayName::Fawry => new FawryGateway($resolved, $this->http),
            GatewayName::Paymob => new PaymobGateway($resolved, $this->http),
            GatewayName::Paylink => new PaylinkGateway($resolved, $this->http),
            GatewayName::Paytabs => new PaytabsGateway($resolved, $this->http),
            GatewayName::PayPal => new PayPalGateway($resolved, $this->http),
            GatewayName::Mpgs => new MpgsGateway($resolved, $this->http),
        };

        if ($this->events instanceof EventDispatcher) {
            $driver = new EventDispatchingGateway($driver, $this->events);
        }

        if ($this->logger instanceof LoggerInterface) {
            return new LoggingGateway($driver, $this->logger);
        }

        return $driver;
    }

    /**
     * Build a driver from a gateway's string key, validating it against GatewayName.
     *
     * @param  string  $name  The gateway's string identifier (the enum backing value).
     * @param  GatewayCredentials|null  $credentials  Explicit credentials, or null to resolve them from configuration.
     * @return PaymentGatewayInterface The concrete gateway driver.
     *
     * @throws GatewayNotSupportedException When the name does not map to a known gateway.
     */
    public function makeByName(string $name, ?GatewayCredentials $credentials = null): PaymentGatewayInterface
    {
        $gateway = GatewayName::tryFrom($name);

        if ($gateway === null) {
            throw GatewayNotSupportedException::forName($name);
        }

        return $this->make($gateway, $credentials);
    }
}
