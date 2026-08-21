# hyprpay/payments — AI Overview

Orientation reference for `hyprpay/payments`, a self-contained multi-gateway payment SDK for PHP/Laravel. Scope: what the package is, its layers, install, resolving/calling a gateway, and the core conventions — links onward for detail.

## What it is

One `PaymentGatewayInterface` (`src/Domain/Contract/PaymentGatewayInterface.php`) that **8 concrete drivers** implement, selected at runtime by a `GatewayName` enum and constructed by `PaymentGatewayFactory`. Callers depend only on the interface and the enum; the concrete driver is chosen at the composition edge.

The 8 drivers (enum case → backing key → driver, under `src/Infrastructure/Gateway/`):

| `GatewayName` case | key | driver |
|---|---|---|
| `CybersourceUnifiedCheckout` | `cybersource_uc` | `CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway` |
| `Fawry` | `fawry` | `Fawry\FawryGateway` |
| `Paymob` | `paymob` | `Paymob\PaymobGateway` |
| `Paylink` | `paylink` | `Paylink\PaylinkGateway` |
| `Paytabs` | `paytabs` | `Paytabs\PaytabsGateway` |
| `PayPal` | `paypal` | `PayPal\PayPalGateway` |
| `Mpgs` | `mpgs` | `Mpgs\MpgsGateway` |
| `AuthorizeNet` | `authorize_net` | `AuthorizeNet\AuthorizeNetGateway` |

Enum: `src/Domain/Enum/GatewayName.php` (backing value = stable machine key used in config + factory lookups; `->label()` for display).

The interface defines every operation the SDK models: `createCheckoutSession`, `requestDccRate`, `charge`, `capture`, `refund`, `void`, `reverseAuthorization`, `enrollPayerAuth`, `validatePayerAuth` (3-D Secure), `vaultInstrument`, `chargeStoredCredential`, `chargeWallet` (Apple Pay / Google Pay), `getTransaction`, `searchTransaction`, `verifyWebhook`, plus `name()`/`credentials()`. Drivers extend `AbstractPaymentGateway`, which rejects operations a given gateway does not support (`UnsupportedOperation`).

## Layers (DDD, under `src/`)

Dependencies point inward; `Domain` depends on nothing.

- **`Domain/`** — framework-agnostic core. No Laravel, no HTTP. Ports (`Contract/`: `PaymentGatewayInterface`, `HttpClient`, `CredentialResolver`, `EventDispatcher`), request DTOs (`Command/`), response DTOs (`Result/`), events (`Event/`), value objects (`ValueObject/`: `Money`, `GatewayCredentials`, …), enums (`Enum/`), exceptions (`Exception/`: `GatewayException` + subtypes).
- **`Application/`** — use-cases: `PaymentGatewayFactory` (make drivers), `TransactionReconciler` + `ReconciliationOutcome` (reconcile use-case).
- **`Infrastructure/`** — the only layer touching Laravel and the network: gateway drivers, driver decorators (`EventDispatchingGateway`, `LoggingGateway`), the HTTP decorator stack, event dispatcher/listener adapters, `ConfigCredentialResolver`, reconcile console commands, and `GatewayServiceProvider`.

Adding a gateway = a new `Infrastructure/Gateway/{X}/` folder, a `GatewayName` case, and one `match` branch in the factory.

See [architecture.md](../architecture.md) for the full tree.

## Install

```bash
composer require hyprpay/payments
```

Laravel package auto-discovery registers `Hyprpay\Payments\Infrastructure\GatewayServiceProvider`. Publish the config:

```bash
php artisan vendor:publish --tag=gateway-config
```

This copies `config/gateway.php` into the host app. Credentials are read from env (see the config-key table in [application-console-config.md](./application-console-config.md)).

## Resolve and call a gateway

Resolve the factory (registered as a container singleton) and `make()` a driver:

```php
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;

$factory = app(PaymentGatewayFactory::class);
$gateway = $factory->make(GatewayName::Paymob);        // or ->makeByName('paymob')

$result = $gateway->charge(new ChargeRequest(
    money: Money::minor(10000, 'EGP'),                 // 100.00 EGP
    orderReference: 'order-123',
    // …instrument / context per ChargeRequest
));
```

- `make(GatewayName, ?GatewayCredentials)` — pass credentials explicitly, or omit to resolve them from config via `ConfigCredentialResolver`.
- `makeByName(string, ?GatewayCredentials)` — same, keyed by the enum backing value; throws `GatewayNotSupportedException` for an unknown key.

Every returned driver is already wrapped in the decorator chain the service provider configured (events, operation logging) — the caller sees only `PaymentGatewayInterface`.

## Core conventions

- **Exact money (minor units).** `Money` (`src/Domain/ValueObject/Money.php`) stores an integer minor amount + ISO-4217 currency + scale — never floats. Build with `Money::minor(10000, 'EGP')` (100.00) or `Money::fromDecimalString('9.60', 'USD')` (scale inferred, round-trips byte-for-byte). All charge/capture/refund/reversal amounts are `Money`.
- **Idempotency keys.** Request DTOs carry an idempotency key so a retried call is not double-charged by the gateway.
- **Deterministic requests.** Requests are built deterministically and idempotently, which is what makes the HTTP retry layer safe.
- **Framework-agnostic ports.** The domain talks to `HttpClient`, `CredentialResolver`, `EventDispatcher` interfaces; Laravel adapters are bound in the service provider. The domain can run without a booted app (e.g. in package tests).
- **Domain events.** When enabled, each driver is wrapped in `EventDispatchingGateway`, which emits a `PaymentEvent` after each lifecycle op (charge, capture, refund, void, reversal, stored-credential charge, vaulting, checkout, webhook). Read/query ops are pass-through. Listen for a specific event class or for the `PaymentEvent` marker interface.
- **Operation logging.** When enabled, `LoggingGateway` wraps the driver and logs each call as `[LoggingGateway] {op}` with gateway, correlation ids, amount, and `duration_ms`. Context is redaction-safe (no PAN/CVV/tokens).
- **HTTP decorator stack.** The bound `HttpClient` is `RetryingHttpClient → [LoggingHttpClient] → [RateLimitingHttpClient] → LaravelHttpClient` (bracketed layers are config-gated). Retries cover HTTP 408/429/5xx and connection timeouts with exponential backoff. See [observability.md](../observability.md).
- **Testing with `FakeHttpClient`.** `src/Infrastructure/Http/FakeHttpClient.php` implements the `HttpClient` port in memory: `queue()` / `queueJson([...], 200)` / raw-body responses (default fallback 200 `{}`), and records every request for assertions — no real HTTP. Inject it into a driver or the factory to test flows.

## Onward

- [application-console-config.md](./application-console-config.md) — Application layer, driver decorators, credential resolver, service-provider wiring, reconcile Artisan commands, and the complete config-key reference.
- [architecture.md](../architecture.md) · [gateways.md](../gateways.md) · [operations.md](../operations.md) · [observability.md](../observability.md)
