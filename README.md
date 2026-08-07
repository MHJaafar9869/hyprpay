# hyprpay/payments

[![CI](https://github.com/MHJaafar9869/hyprpay/actions/workflows/ci.yml/badge.svg)](https://github.com/MHJaafar9869/hyprpay/actions/workflows/ci.yml)

📖 **Documentation:** <https://mhjaafar9869.github.io/hyprpay/>

A self-contained, multi-gateway payment SDK for PHP. One clean interface, a factory
that resolves the right driver, and a swappable HTTP transport — with four gateways
built in: **CyberSource Unified Checkout**, **Fawry**, **Paymob**, and **PayLink**.

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
  HMAC-SHA512, PayLink HMAC-SHA256), so there are no heavy third-party gateway
  dependencies.
- **Deterministic & idempotent** — request bodies are built deterministically (no
  hidden `uniqid()`/`time()`), and write operations carry an idempotency key.
- **Exact money** — amounts are carried as minor units and never rounded.
- **Statically strict** — PHPStan **level max, zero baseline**; formatted with Pint;
  refactor-checked with Rector; 90+ Pest tests.

## Requirements

- PHP `^8.2`
- `illuminate/support` and `illuminate/http` `^10 | ^11 | ^12 | ^13`

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

### A sample per gateway

The same injected `PaymentGatewayFactory` drives every gateway. Each class below is
self-contained.

**CyberSource Unified Checkout** — mint a capture context for the widget (then charge
the transient token it returns, as in the `ChargeInvoice` example above):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;

final readonly class StartCybersourceCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::CybersourceUnifiedCheckout)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(10000, 'EGP'),
                targetOrigins: ['https://shop.test'],
            ));

        // hand $session->jwt to the Unified Checkout widget on the front end
    }
}
```

**Fawry** — start a hosted checkout and redirect the payer:

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;

final readonly class StartFawryCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::Fawry)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(15000, 'EGP'),
                orderReference: 'ORDER-124',
                returnUrl: 'https://shop.test/return',
                customer: new Customer(email: 'ada@shop.test', firstName: 'Ada', lastName: 'Lovelace'),
                // paymentMethod: 'PAYATFAWRY' → $session->reference; 'PayUsingCC' / 'MWALLET' also supported
            ));

        // redirect the payer to $session->redirectUrl (hosted page)
    }
}
```

**Paymob** — runs the auth → order → payment-key flow and returns the iframe URL
(integration/iframe ids come from `GatewayCredentials::$extra` or, as here, `options`):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;

final readonly class StartPaymobCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::Paymob)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(15000, 'EGP'),
                orderReference: 'ORDER-125',
                paymentMethod: 'card',
                customer: new Customer(email: 'ada@shop.test', firstName: 'Ada', lastName: 'Lovelace'),
                options: ['integration_id' => 111111, 'iframe_id' => 222222, 'customer_mobile' => '01000000000'],
            ));

        // redirect to $session->redirectUrl (the Paymob iframe); Paymob order id is $session->reference
    }
}
```

**PayLink** — create an invoice and redirect to the hosted checkout (or pass
`options: ['iframe' => true]` to get an iframe-ready `redirectUrl` to embed instead):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;

final readonly class StartPaylinkCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::Paylink)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(25000, 'USD'),
                orderReference: 'ORDER-126',
                description: 'Gold Plan',
                returnUrl: 'https://shop.test/return',
                customer: new Customer(email: 'john@example.com', firstName: 'John', lastName: 'Doe'),
                options: ['webhook_url' => 'https://shop.test/webhook', 'iframe' => true],
            ));

        // embed $session->redirectUrl in an <iframe> (or redirect to it without iframe);
        // reconcile later by $session->reference (invoice id)
    }
}
```

In iframe mode the returned `redirectUrl` is embedded rather than redirected to. The
frame needs `allow="payment *"` (so Apple Pay / Google Pay work inside it), and the
checkout signals completion to the parent window via `postMessage` — a
`{ type: 'paylink_payment', success }` event from your PayLink origin — instead of
redirecting. The embedding page's origin must match your integration's registered
Origin, or the browser blocks framing.

```html
<iframe src="$session->redirectUrl" allow="payment *"
        style="width:100%;min-height:640px;border:0" title="Secure checkout"></iframe>
<script>
  addEventListener('message', function (e) {
    if (e.origin !== 'https://pay.getpayin.com') return;
    if (e.data?.type !== 'paylink_payment') return;
    window.location.href = e.data.success ? '/thank-you' : '/checkout?failed=1';
  });
</script>
```

Passing credentials explicitly per call — this skips the resolver, so it works for
any dynamic source (a one-off override, per-merchant, per-tenant, …):

```php
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;

$gateway = $factory->make(GatewayName::Fawry, new GatewayCredentials(
    host: 'atfawry.fawrystaging.com',
    merchantId: $merchantCode,
    apiKeyId: '',
    sharedSecret: $secureKey,
    testMode: true,
));
```

## Gateways & operations

Every driver implements the same `PaymentGatewayInterface`. Operations a gateway does
not support throw `UnsupportedOperationException`, so you can rely on the same surface
everywhere.

| Operation | CyberSource UC | Fawry | Paymob | PayLink |
| --- | :---: | :---: | :---: | :---: |
| `createCheckoutSession` | ✅ capture context | ✅ hosted / card / wallet / pay-at-Fawry / MyFawry / instalment | ✅ iframe flow | ✅ invoice link / iframe |
| `charge` (transient token) | ✅ | — | — | — |
| `capture` | ✅ | ✅ (Auth/Capture) | ✅ | ✅ (settle) |
| `refund` | ✅ | ✅ | ✅ | ✅ |
| `void` | ✅ | ✅ (cancel auth) | ✅ | ✅ |
| `reverseAuthorization` | ✅ | — | — | ✅ |
| `enrollPayerAuth` / `validatePayerAuth` (3-DS) | ✅ | — | — | — |
| `vaultInstrument` / `chargeStoredCredential` | ✅ (TMS, MIT/CIT) | — | — | — |
| `getTransaction` / `searchTransaction` | ✅ | ✅ | ✅ | ✅ |
| `verifyWebhook` | ✅ | ✅ | ✅ | ✅ |

Provider-specific inputs (e.g. Fawry payment method, Paymob integration/iframe ids,
card or wallet details) are passed through `CheckoutSessionRequest::$options` and the
`GatewayCredentials::$extra` bag.

## Idempotency

Retries are safe. Every write is idempotent through two guarantees:

1. **Deterministic request bodies** — the SDK never injects `uniqid()`, `time()`, or
   `rand()`, so the same inputs always produce a byte-for-byte identical request.
2. **An idempotency key on every write** — `charge`, `capture`, `refund`, `void`,
   `reverseAuthorization`, and `chargeStoredCredential` carry an `idempotencyKey`, sent
   to the gateway's native deduplication mechanism:

| Gateway | Mechanism |
| --- | --- |
| CyberSource UC | `v-c-idempotency-key` header |
| Fawry | `merchantRefNum` (= your order reference) |
| Paymob | `merchant_order_id` (= your order reference) |
| PayLink | `Idempotency-Key` header |

For `charge` and `chargeStoredCredential` the key defaults to `orderReference`, so a
retried charge for the same order is deduplicated automatically. For `capture`,
`refund`, `void`, and `reverseAuthorization`, pass an explicit key that is unique to
the logical operation (partial captures/refunds each need their own key):

```php
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Command\RefundRequest;

// Retrying this with the same key is a no-op at the gateway — never a double refund.
$gateway->refund(new RefundRequest(
    transactionId: '7040000000000000001',
    money: Money::minor(2500, 'EGP'),
    idempotencyKey: 'refund:invoice-123:attempt-1',
));
```

Webhooks are the one place the SDK cannot dedupe for you — a gateway may deliver the
same event more than once, and the signatures carry no timestamp. Verify the
signature, then apply your own idempotency on the transaction/invoice id.

## Webhooks

Verify inbound callbacks with the same driver:

```php
$event = $gateway->verifyWebhook($request->getContent(), $request->headers->all());

if ($event->verified) {
    // $event->eventType, $event->transactionId, $event->status, $event->payload
}
```

Webhook signatures are unauthenticated until verified and carry no timestamp — always
pair verification with your own idempotency on the transaction/invoice id.

## Reconciliation

Each gateway ships an Artisan command that fetches the **authoritative** current status
of one or more transactions straight from the gateway (via `getTransaction`) — handy for
scheduled reconciliation or spot-checking a payment against your own records:

```bash
php artisan gateway:reconcile:cybersource_uc 7040000000000000001
php artisan gateway:reconcile:fawry ORDER-124 ORDER-125   # accepts multiple ids
php artisan gateway:reconcile:paymob 123456789
php artisan gateway:reconcile:paylink INV-0001
```

Each command prints a table of the transaction id, normalized `PaymentStatus`, amount, and
order reference. A failed lookup for one id is reported inline without aborting the rest,
and the command **exits non-zero when any id could not be reconciled**, so it drops
straight into a scheduler or CI health check. The reconciliation logic itself lives in the
framework-agnostic `Application\TransactionReconciler`, so you can call it directly instead
of shelling out.

The commands are registered only while running in the console and can be turned off in
config (e.g. when the host app ships its own tooling):

```php
// config/gateway.php
'commands' => [
    'reconcile' => (bool) env('GATEWAY_RECONCILE_COMMANDS', true),
],
```

## Architecture

The code is organised in three DDD layers under `src/`, each its own namespace:

```
Application/     PaymentGatewayFactory ── makes ──▶ Domain\Contract\PaymentGatewayInterface
                 TransactionReconciler (reconcile use-case) · ReconciliationOutcome

Domain/          the framework-agnostic core — no Laravel, no HTTP
  Contract/        PaymentGatewayInterface (one fat contract), HttpClient, CredentialResolver (ports)
  AbstractPaymentGateway (default: UnsupportedOperation)
  Command/         request DTOs   (ChargeRequest, CheckoutSessionRequest, RefundRequest, …)
  Result/          response DTOs  (PaymentResult, CheckoutSession, TransactionSnapshot, WebhookEvent, …)
  ValueObject/     Money, Customer, BillingAddress, GatewayCredentials
  Enum/            GatewayName, PaymentStatus, CredentialInitiator
  Exception/       GatewayException + subtypes (UnsupportedOperation, WebhookVerification, …)

Infrastructure/  adapters for the ports — the only layer that touches Laravel & the network
  Gateway/{X}/     CybersourceUnifiedCheckout · Fawry · Paymob · Paylink (extend AbstractPaymentGateway)
  Http/            HttpClient decorator stack: RetryingHttpClient → LoggingHttpClient → RateLimitingHttpClient → LaravelHttpClient · FakeHttpClient (tests)
  Credentials/     ConfigCredentialResolver
  Console/         ReconcileCommand (base) + one gateway:reconcile:{X} command per gateway
  GatewayServiceProvider (wires the ports + factory into the container, registers commands)
```

Dependencies point inward: `Infrastructure` and `Application` depend on `Domain`; the
`Domain` depends on nothing. Adding a gateway is a new `Infrastructure/Gateway/{X}/`
folder, a `GatewayName` case, and one factory branch.

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
