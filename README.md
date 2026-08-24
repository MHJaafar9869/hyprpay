# hyprpay/payments

[![CI](https://github.com/MHJaafar9869/hyprpay/actions/workflows/ci.yml/badge.svg)](https://github.com/MHJaafar9869/hyprpay/actions/workflows/ci.yml)
[![Latest Release](https://img.shields.io/github/v/release/MHJaafar9869/hyprpay)](https://github.com/MHJaafar9869/hyprpay/releases/latest)
[![PHP Version](https://img.shields.io/badge/php-%E2%89%A5%208.2-777bb4)](composer.json)
[![License: MIT](https://img.shields.io/github/license/MHJaafar9869/hyprpay)](LICENSE)

📦 **Package:** [hyprpay/payments on Packagist](https://packagist.org/packages/hyprpay/payments)

📖 **Documentation:** [HyprPay docs](https://mhjaafar9869.github.io/hyprpay/)

## Requirements

- PHP `^8.2`
- `illuminate/support` and `illuminate/http` `^10 | ^11 | ^12 | ^13`
- `firebase/php-jwt` `^6.10 | ^7.0` (CyberSource orchestrated-flow result-JWT verification)

## Installation

Install from the package repository (or add it as a Composer `path` repository when
developing locally):

```bash
composer require hyprpay/payments
```

The `GatewayServiceProvider` is auto-discovered. Publish the config if you want to
tweak the defaults:

```bash
php artisan vendor:publish --tag=gateway-config
```

A self-contained, multi-gateway payment SDK for PHP. One clean interface, a factory
that resolves the right driver, and a swappable HTTP transport — with nine gateways
built in: **CyberSource Unified Checkout**, **Fawry**, **Paymob**, **PayLink**,
**PayTabs**, **PayPal**, **Mastercard Payment Gateway Services**, **Authorize.Net**,
and **Airwallex**.

- **Domain-driven layering** — a pure `Domain` (contracts, commands, results, value
  objects, enums), a thin `Application` layer (`PaymentGatewayFactory`), and an
  `Infrastructure` layer (the gateway drivers and Laravel adapters). Business rules
  never depend on the framework.
- **Factory + single interface** — resolve any gateway through `PaymentGatewayFactory`
  and program against one `PaymentGatewayInterface`.
- **Ports & adapters** — the `HttpClient` and `CredentialResolver` ports live in the
  `Domain`; their adapters live in `Infrastructure`. The HTTP port ships a Laravel
  adapter (wrapped with retrying, plus optional rate-limiting and logging decorators)
  for production and an in-memory fake for tests, keeping the core transport- and
  framework-agnostic.
- **Raw REST, no vendor SDKs** — every driver speaks the gateway's REST API directly
  and signs requests itself (CyberSource HMAC HTTP-Signature, Fawry SHA-256, Paymob
  HMAC-SHA512, PayLink HMAC-SHA256, PayTabs server-key auth + HMAC-SHA256 callbacks,
  PayPal OAuth 2.0 client credentials + API webhook-signature verification, Mastercard
  MPGS HTTP Basic auth, Authorize.Net name/transaction-key auth + HMAC-SHA512 webhooks,
  Airwallex API-access login token + HMAC-SHA256 webhooks), so there are no heavy
  third-party gateway dependencies.
- **Deterministic & idempotent** — request bodies are built deterministically (no
  hidden `uniqid()`/`time()`), and write operations carry an idempotency key.
- **Exact money** — amounts are carried as minor units and never rounded.
- **Statically strict** — PHPStan **level max, zero baseline**; formatted with Pint;
  refactor-checked with Rector; 370+ Pest tests.

## Quick start

The `GatewayServiceProvider` registers `PaymentGatewayFactory` (and the `HttpClient`
and `CredentialResolver` ports) in the container, so inject the factory via the
constructor — no service location, no `new`:

```php
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;

final readonly class ChargeInvoice
{
    // Type-hint the factory; Laravel resolves and injects it automatically.
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(string $tokenFromWidget): void
    {
        // Credentials resolve from config by default; pass them explicitly to override.
        $gateway = $this->gateways->make(GatewayName::CybersourceUnifiedCheckout);

        $result = $gateway->charge(new ChargeRequest(
            transientToken: $tokenFromWidget,
            money: Money::minor(10000, 'EGP'), // 100.00 EGP, exact minor units
            orderReference: 'ORDER-123',       // also the idempotency key
        ));

        if ($result->success) {
            // $result->status, $result->transactionId, $result->raw
        }
    }
}
```

Prefer to swap the transport or credential source? Bind the ports in a service
provider — the factory depends only on the `HttpClient` and `CredentialResolver`
interfaces:

```php
use Hyprpay\Payments\Domain\Contract\CredentialResolver;
use Hyprpay\Payments\Domain\Contract\HttpClient;

$this->app->bind(HttpClient::class, MyHttpClient::class);
$this->app->bind(CredentialResolver::class, MyCredentialResolver::class);
```

`MyHttpClient` and `MyCredentialResolver` are your own classes — each implements the
port interface it is bound to (`HttpClient` sends the outbound gateway requests;
`CredentialResolver` supplies the per-gateway credentials). Both bindings are optional:
out of the box the SDK binds a retrying Laravel HTTP adapter (`LaravelHttpClient`, with
optional rate-limiting/logging decorators) and a config-driven `ConfigCredentialResolver`,
so bind only the port you want to replace.

## Monitoring dashboard

An opt-in operator dashboard — off by default — mounts at `/hyprpay` to watch gateway
activity: each gateway's health (configured vs not, test vs live, the default), headline
stats and a live recent-activity feed, plus a look-up-by-reference panel that queries the
gateway directly. Enable it (and, separately, the activity store that feeds the feed) via
env:

```dotenv
GATEWAY_DASHBOARD=true          # mount the dashboard routes/views
GATEWAY_DASHBOARD_STORE=true    # record activity into the (cache-backed) feed
# GATEWAY_DASHBOARD_PATH=hyprpay
# GATEWAY_DASHBOARD_LIMIT=500
```

Access is gated exactly like Telescope/Horizon: every request must pass the configured
`gateway.dashboard.middleware` stack (default `['web']`) **and** satisfy the `viewHyprpay`
gate. The default gate allows only the local environment — open it to real operators from
any service provider:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewHyprpay', fn ($user = null) => $user?->isAdmin() === true);
```

The activity store is a bounded cache ring buffer by default (no database, no migration);
bind a custom `PaymentActivityRepository` to persist durable history instead. The view is
self-contained (inline CSS/JS, no build step) and publishable with
`php artisan vendor:publish --tag=gateway-dashboard-views`.

## Gateways

Nine drivers behind one `PaymentGatewayInterface`: **CyberSource Unified Checkout**,
**Fawry**, **Paymob**, **PayLink**, **PayTabs**, **PayPal**, **Mastercard Payment
Gateway Services**, **Authorize.Net**, and **Airwallex**. Operations a gateway does not support throw
`UnsupportedOperationException`, so the same surface holds everywhere. A runnable sample
per gateway and the full operation-support matrix live in the docs below.

## Documentation

The [hosted docs](https://mhjaafar9869.github.io/hyprpay/) are browsable by gateway.
The reference is split into focused guides:

- **[Gateways & operations](docs/guides/gateways.md)** — a runnable sample per gateway and the operation-support matrix.
- **[Payment operations](docs/guides/operations.md)** — Dynamic Currency Conversion, idempotency, webhooks, and reconciliation.
- **[Events & operation logging](docs/guides/observability.md)** — the domain events every driver emits and per-operation logging.
- **[Architecture](docs/guides/architecture.md)** — the DDD layering and how to add a gateway.
- **[AI & MCP reference](docs/guides/ai/README.md)** — a machine-consumable, 100%-coverage reference for AI assistants, the **developer MCP server** that ships in [`mcp/`](mcp/) (read-only tools that reflect the SDK so coding agents can explore it and generate correct integrations), and a guide to exposing the SDK's operations as MCP tools.

## Testing & quality

The package ships a full quality gate. From the package directory:

```bash
composer test      # Pest
composer format    # Pint (write)
composer analyse   # PHPStan, level max
composer rector    # Rector
composer check     # format:test + rector:dry + analyse + test
```

Tests are database-free and never hit the network — they exercise the drivers through
the in-memory `FakeHttpClient`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). By participating you agree to the
[Code of Conduct](CODE_OF_CONDUCT.md). To report a vulnerability, follow
[SECURITY.md](SECURITY.md).

## License

Released under the [MIT License](LICENSE).
