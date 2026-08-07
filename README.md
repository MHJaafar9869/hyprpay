# hyprpay/payments

[![CI](https://github.com/MHJaafar9869/hyprpay/actions/workflows/ci.yml/badge.svg)](https://github.com/MHJaafar9869/hyprpay/actions/workflows/ci.yml)

📖 **Documentation:** <https://mhjaafar9869.github.io/hyprpay/>

A self-contained, multi-gateway payment SDK for PHP. One clean interface, a factory
that resolves the right driver, and a swappable HTTP transport — with six gateways
built in: **CyberSource Unified Checkout**, **Fawry**, **Paymob**, **PayLink**,
**PayTabs**, and **PayPal**.

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
  PayPal OAuth 2.0 client credentials + API webhook-signature verification), so there
  are no heavy third-party gateway dependencies.
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
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobCheckoutOptions;

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
                options: new PaymobCheckoutOptions(integrationId: 111111, iframeId: 222222, customerMobile: '01000000000'),
            ));

        // redirect to $session->redirectUrl (the Paymob iframe); Paymob order id is $session->reference
    }
}
```

**PayLink** — create an invoice and redirect to the hosted checkout (or pass
`options: new PaylinkCheckoutOptions(iframe: true)` to get an iframe-ready `redirectUrl` to embed instead):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Infrastructure\Gateway\Paylink\PaylinkCheckoutOptions;

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
                options: new PaylinkCheckoutOptions(webhookUrl: 'https://shop.test/webhook', iframe: true),
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

**PayTabs** — start a hosted payment page and redirect the payer (pass
`paymentMethod: 'auth'` to place a hold you capture later, and `PaytabsCheckoutOptions::$webhookUrl`
for the server-to-server IPN callback):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsCheckoutOptions;

final readonly class StartPaytabsCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::Paytabs)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(12030, 'SAR'),
                orderReference: 'ORDER-127',
                description: 'Gold Plan',
                returnUrl: 'https://shop.test/return',
                customer: new Customer(email: 'john@example.com', firstName: 'John', lastName: 'Doe'),
                options: new PaytabsCheckoutOptions(webhookUrl: 'https://shop.test/ipn'),
            ));

        // redirect to $session->redirectUrl (the PayTabs hosted page);
        // reconcile later by $session->reference (the PayTabs tran_ref)
    }
}
```

**PayPal** — create an order and redirect the buyer to PayPal to approve it, then
complete the order once they return (pass `paymentMethod: 'authorize'` to place a hold
you capture later instead of capturing on approval):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\PayPalCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalUserAction;

$paypal = $factory->make(GatewayName::PayPal);

// 1. Create the order and send the buyer to PayPal to approve it.
$session = $paypal->createCheckoutSession(new CheckoutSessionRequest(
    money: Money::minor(10000, 'USD'),
    orderReference: 'ORDER-127',
    description: 'Gold Plan',
    returnUrl: 'https://shop.test/return',   // PayPal redirects here after approval
    options: new PayPalCheckoutOptions(      // typed, per-gateway options — no ambiguous array
        cancelUrl: 'https://shop.test/cancel',
        brandName: 'Example',
        userAction: PayPalUserAction::PayNow,
    ),
));

// redirect to $session->redirectUrl (PayPal's approval page);
// $session->reference is the PayPal order id — keep it for step 2.

// 2. After the buyer approves and PayPal redirects them back, complete the order.
$result = $paypal->charge(new ChargeRequest(
    transientToken: $session->reference,     // the approved PayPal order id
    money: Money::minor(10000, 'USD'),
));
// $result->status is Captured (or Authorized when charge sets capture: false),
// and $result->transactionId is the capture/authorization id for follow-ons.
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

| Operation | CyberSource UC | Fawry | Paymob | PayLink | PayTabs | PayPal |
| --- | :---: | :---: | :---: | :---: | :---: | :---: |
| `createCheckoutSession` | ✅ capture context | ✅ hosted / card / wallet / pay-at-Fawry / MyFawry / instalment | ✅ iframe flow | ✅ invoice link / iframe | ✅ hosted / invoice / paylink / managed | ✅ order → approval redirect |
| `charge` (transient token) | ✅ | — | — | — | ✅ Own Form (payment token) | ✅ complete approved order² |
| `capture` | ✅ | ✅ (Auth/Capture) | ✅ | ✅ (settle) | ✅ | ✅ |
| `refund` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `void` | ✅ | ✅ (cancel auth) | ✅ | ✅ | ✅ | ✅ |
| `reverseAuthorization` | ✅ | — | — | ✅ | ✅ (release) | — |
| `enrollPayerAuth` / `validatePayerAuth` (3-DS) | ✅ | — | — | — | — | — |
| `vaultInstrument` / `chargeStoredCredential` | ✅ (TMS, MIT/CIT) | — | — | — | ✅ token (MIT/CIT)¹ | ✅ vault (MIT/CIT) |
| `requestDccRate` (Dynamic Currency Conversion) | ✅ | — | — | — | — | — |
| `getTransaction` / `searchTransaction` | ✅ | ✅ | ✅ | ✅ | ✅ query | ✅ order lookup |
| `verifyWebhook` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ API |

Provider-specific inputs (e.g. Fawry payment method, Paymob integration/iframe ids,
card or wallet details) are passed through `CheckoutSessionRequest::$options` and the
`GatewayCredentials::$extra` bag. `$options` is a typed, per-gateway options DTO
implementing `CheckoutOptions` — `FawryCheckoutOptions`, `PaymobCheckoutOptions`,
`PaylinkCheckoutOptions`, `PaytabsCheckoutOptions`, and `PayPalCheckoutOptions` — so
every field is named and type-checked (including enums like `PayPalUserAction` and
nested value objects like `PaytabsAgreement` / `PaytabsSplitPayout` / `PaytabsLineItem`
and `FawryCard`) rather than stringly-typed. Each DTO's static `fromArray()` builds one
from a raw config array when you need to; each driver narrows `$options` to its own type.

For **PayTabs**, `paymentMethod` selects the integration type: `invoice` (an emailable
Invoice link), `managed` (an iframe-embeddable Managed Form), `paylink` (a reusable
PayLink), or the default Hosted Payment Page (pass `auth` for a hold to capture later).
To keep the payer on your own site instead of redirecting, either embed the Hosted Page
with `new PaytabsCheckoutOptions(iframe: true)` (optionally `framedReturnTop`,
`framedReturnParent`, `framedMessageTarget` — an HTTPS URL on your domain that
receives a `postMessage` when payment finishes so you can close the frame), or use
**Own Form**: collect the card in your own form, tokenise it in the browser with
PayTabs' client-side library, and charge the resulting `payment_token` via `charge()`
(the raw PAN never touches your server, keeping you in the light PCI tier):

```php
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;

$result = $gateway->charge(new ChargeRequest(
    transientToken: $paymentToken,          // browser-generated by PayTabs' JS
    money: Money::minor(9500, 'SAR'),
    orderReference: 'ORDER-127',
));

// 3-D Secure card → $result->status is Pending and $result->raw['redirect_url']
// holds the bank's 3DS page; a non-3DS card returns the final result inline.
```

Pass a
`PaytabsAgreement` on the options' `agreement` (description, `repeatAmount`, `repeatEvery`,
`firstInstallmentDueDate`, …) to start a Repeat Billing agreement — the customer completes
the initial payment and consents, then PayTabs auto-bills the schedule (recurring execution
and pause/cancel are managed PayTabs-side, not via the SDK). Pass a list of
`PaytabsSplitPayout` on `splitPayout` (each with its `itemTotal`, `mscFlag`, and
`PaytabsBeneficiary` details) to split the settled funds across beneficiaries after payment.

¹ PayTabs has no raw-PAN vault endpoint, so `vaultInstrument` is unsupported. Instead a
reusable card token is created by setting `PaytabsCheckoutOptions::$tokenise` (1–6, e.g. `2` = Hex32)
on any checkout — the token arrives in the callback/status `token` field — then charged
later with `chargeStoredCredential` (merchant-initiated → `recurring`, customer-initiated
→ `ecom`) and revoked with the driver's `deleteToken()`.

For **PayPal**, the driver speaks Orders v2 / Payments v2 and authenticates with OAuth 2.0
client credentials (client id → `merchant_id`, client secret → `shared_secret`), fetching a
bearer token once per request and reusing it across calls. `createCheckoutSession` creates
an order (intent `CAPTURE`, or `AUTHORIZE` when `paymentMethod: 'authorize'`) and returns
the buyer-approval redirect (`payer-action` link) plus the order id; `charge` then completes
that approved order — its `transientToken` is the order id, capturing when `capture` is true
and authorizing when false. Follow-ons act on the resulting payment resources: `capture`
and `void` take an authorization id, `refund` a capture id, and `getTransaction` reads an
order back by id. Cards are vaulted with `vaultInstrument` (PayPal's setup-token → payment-token
flow) and charged card-on-file via `chargeStoredCredential`, which stamps the network
stored-credential metadata (MIT → `RECURRING`, CIT → `ONE_TIME`). `verifyWebhook` posts the
notification's `PayPal-Transmission-*` headers to PayPal's verify-signature API using the
configured `webhook_id` (`PAYPAL_WEBHOOK_ID` → `webhook_secret`), so it makes one live call
rather than checking a local HMAC.

² For **PayPal**, `charge`'s `transientToken` is not a card token but the id of an order the
buyer has already approved on PayPal (returned by `createCheckoutSession`); calling `charge`
captures or authorizes that order server-to-server.

## Dynamic Currency Conversion (DCC)

Let a foreign cardholder pay in their own currency at a rate you quote up front. Ask
CyberSource for a rate with `requestDccRate`, then thread the returned `DccQuote` into
the charge — `charge`, `capture`, `refund`, and `reverseAuthorization` all accept it, so
the *same* quoted rate is echoed across the whole lifecycle. Set `money` to the quote's
`convertedAmount` (the cardholder's billing currency); the original merchant amount and
exchange rate ride along on the quote.

```php
use Hyprpay\Payments\Domain\Command\DccRateRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;

$cybersource = $factory->make(GatewayName::CybersourceUnifiedCheckout);

// 1. Quote a rate for 480.00 EGP against the cardholder's card.
$quote = $cybersource->requestDccRate(new DccRateRequest(
    money: Money::minor(48000, 'EGP'),
    cardNumber: '4111111111111111',
));

// 2. If DCC is offered, present $quote->convertedAmount to the cardholder, then charge it.
if ($quote->offered) {
    $result = $cybersource->charge(new ChargeRequest(
        transientToken: $tokenFromWidget,
        money: $quote->convertedAmount, // the cardholder's billing amount, at the quoted rate
        dcc: $quote,                    // same rate id echoed on capture/refund too
        orderReference: 'ORDER-123',
    ));
}
```

`money` supplies the billing amount and currency; the quote supplies the original merchant
amount, the exchange rate, and the `currencyConversion.id` that pins the transaction to the
rate CyberSource returned — all echoed unchanged on capture, refund, and reversal.

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
| PayTabs | `cart_id` (= your order reference) |
| PayPal | `PayPal-Request-Id` header |

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
php artisan gateway:reconcile:paypal 7NK74838L4813105R   # a PayPal order id
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

## Events

Every gateway driver is wrapped so it emits a **payment domain event** after each lifecycle
operation — `charge`, `capture`, `refund`, `void`, `reverseAuthorization`,
`chargeStoredCredential`, `vaultInstrument`, `createCheckoutSession`, and `verifyWebhook`.
The event fires on completion regardless of success (the result's `success`/`status` carries
the outcome), so you can react to declines too; if the call throws, no event is dispatched.

Every event implements the marker interface `Domain\Event\PaymentEvent`, so **one listener
subscribed to the interface receives them all** — no listener-per-event boilerplate. Route
them with a single `match` on the concrete type:

```php
use Hyprpay\Payments\Domain\Event\AuthorizationReversed;
use Hyprpay\Payments\Domain\Event\PaymentCaptured;
use Hyprpay\Payments\Domain\Event\PaymentEvent;
use Hyprpay\Payments\Domain\Event\PaymentRefunded;
use Hyprpay\Payments\Domain\Event\WebhookReceived;

final class PaymentEventSubscriber
{
    /** One handler for every payment event. */
    public function handle(PaymentEvent $event): void
    {
        match (true) {
            $event instanceof PaymentCaptured       => $this->markOrderPaid($event->orderReference, $event->result),
            $event instanceof PaymentRefunded       => $this->recordRefund($event->transactionId, $event->result),
            $event instanceof AuthorizationReversed => $this->releaseHold($event->transactionId),
            $event instanceof WebhookReceived       => $this->applyWebhook($event->webhook),
            default                                 => null, // charge/void/vault/checkout — ignored here
        };
    }

    // ...markOrderPaid(), recordRefund(), releaseHold(), applyWebhook()
}
```

Register it once against the interface — it then receives every event:

```php
use Illuminate\Support\Facades\Event;

Event::listen(PaymentEvent::class, PaymentEventSubscriber::class);

// ...or skip the match and target a single operation directly:
Event::listen(PaymentRefunded::class, function (PaymentRefunded $event): void {
    if ($event->result->success) {
        // $event->transactionId, $event->money, $event->result->refundId
    }
});
```

The events and the payload each carries:

| Event | Payload (besides `gateway()`) |
| --- | --- |
| `CheckoutSessionCreated` | `orderReference`, `money`, `session` |
| `PaymentCharged` | `orderReference`, `money`, `result` |
| `PaymentCaptured` | `transactionId`, `orderReference`, `money`, `result` |
| `PaymentRefunded` | `transactionId`, `orderReference`, `money`, `result` |
| `PaymentVoided` | `transactionId`, `orderReference`, `result` |
| `AuthorizationReversed` | `transactionId`, `orderReference`, `money`, `result` |
| `StoredCredentialCharged` | `paymentInstrumentId`, `orderReference`, `money`, `result` |
| `InstrumentVaulted` | `customerReference`, `result` |
| `WebhookReceived` | `webhook` |

Events are **queue-safe**: they carry only the gateway, correlation ids, amount, and the
normalized result — never the raw request, which can hold a PAN — so a queued listener never
serializes card data.

Toggle events and the built-in audit-logging listener (which records a redaction-safe line
per event through your PSR-3 logger — gateway, ids, and status, never `raw` payloads or card
data):

```php
// config/gateway.php
'events' => [
    'enabled' => (bool) env('GATEWAY_EVENTS', true),  // wrap drivers to emit events
    'log' => (bool) env('GATEWAY_EVENTS_LOG', false),  // attach the audit-logging listener
],
```

With `enabled` off the factory returns bare drivers and nothing is dispatched. Dispatch goes
through the framework-agnostic `Domain\Contract\EventDispatcher` port (the Laravel adapter
forwards to the application's event dispatcher), so the core stays framework-independent.

## Operation logging

Enable operation logging to wrap every driver in a `LoggingGateway` that logs each call —
`charge`, `capture`, `refund`, `getTransaction`, `verifyWebhook`, … — with its **duration**
and a safe correlation context (gateway, order/transaction ids, amount) through your PSR-3
logger. Normally you just flip the config toggle (`logging.operations` below) and the factory
does the wrapping for you. The SDK writes to its **own** daily channel —
`storage/logs/hyprpay-YYYY-MM-DD.log` by default — kept out of your app log (set
`logging.channel` to route it into a channel you've defined instead).

`LoggingGateway` is a plain decorator, so it's constructed at the composition edge (by the
factory / service provider, like `LoggingHttpClient` and `EventDispatchingGateway`) rather than
resolved from the container — the container can't know which inner driver and credentials to
inject. To compose it yourself — outside Laravel, or around a driver you built — wrap it with
any PSR-3 logger:

```php
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Infrastructure\Gateway\LoggingGateway;
use Illuminate\Support\Facades\Log;

final class PaymentGatewayProvider
{
    public function __construct(private PaymentGatewayFactory $factory) {}

    /**
     * Resolve a PayPal gateway that logs every operation with its duration.
     *
     * Wraps the driver in a LoggingGateway, so each call is recorded as
     * `[LoggingGateway] {operation}` through the "payments" channel with a masked,
     * PAN-free context. Enabling `gateway.logging.operations` makes the factory do this
     * automatically, so this wrapper becomes unnecessary.
     */
    public function payments(): PaymentGatewayInterface
    {
        return new LoggingGateway(
            $this->factory->make(GatewayName::PayPal),
            Log::channel('payments'),
        );
    }
}
```

Each call is logged at info as `[{gateway}] {operation}` — the message plus a structured context:

```text
[paypal] charge
{
    "gateway": "paypal",
    "order_reference": "ORDER-123",
    "amount": "100.00",
    "currency": "USD",
    "duration_ms": 84.2
}
```

The log is identified by **gateway + operation** — a generic decorator can't know your calling
class, so it doesn't pretend to. If you want the *initiator's* name (`action`), use the `LogsAction`
trait directly in your own action/service, where `action` becomes your class.

**Request correlation** (`request_id`, `ip`, `url`) isn't added by the SDK — it stays framework-
agnostic and runs in CLI/queue where there is no request. Add it once to your app's log context
so it lands on every line, these included (the timestamp is already stamped by the logger):

```php
// e.g. in middleware
Log::shareContext(['request_id' => (string) Str::uuid(), 'ip' => $request->ip(), 'url' => $request->fullUrl()]);

// or tag one wrapper with static extra fields via the constructor hook:
new LoggingGateway($driver, $logger, ['component' => 'checkout']);
```

With that in place the same call lands in the SDK's daily file
(`storage/logs/hyprpay-2026-08-08.log`) as — timestamp from the logger, `request_id`/`ip`/`url`
from your shared context, the rest from the SDK:

```text
[2026-08-08 10:15:42] production.INFO: [paypal] charge
{
    "request_id": "9b1e5b1e-3c2a-4f77-9c1e-2b0f5a7d1e42",
    "ip": "203.0.113.7",
    "url": "https://shop.test/checkout",
    "gateway": "paypal",
    "order_reference": "ORDER-123",
    "amount": "100.00",
    "currency": "USD",
    "duration_ms": 84.2
}
```

The context carries **no
PAN, cvv, or tokens**, and the underlying `LogsAction` trait masks sensitive keys as a
backstop. This is distinct from `http.logging`, which logs the lower-level HTTP request/response
metadata. `LogsAction` (`Infrastructure\Support\Concerns\LogsAction`) is reusable on any class
that exposes a PSR-3 `logger()`, offering level helpers (`logInfo`/`logError`/…), a
class-name-prefixed message, sensitive-key masking, and `logTimedAction()` for timed calls.

## Architecture

The code is organised in three DDD layers under `src/`, each its own namespace:

```
Application/     PaymentGatewayFactory ── makes ──▶ Domain\Contract\PaymentGatewayInterface
                 TransactionReconciler (reconcile use-case) · ReconciliationOutcome

Domain/          the framework-agnostic core — no Laravel, no HTTP
  Contract/        PaymentGatewayInterface (one fat contract), HttpClient, CredentialResolver, EventDispatcher (ports)
  AbstractPaymentGateway (default: UnsupportedOperation)
  Command/         request DTOs   (ChargeRequest, CheckoutSessionRequest, RefundRequest, …)
  Result/          response DTOs  (PaymentResult, CheckoutSession, TransactionSnapshot, WebhookEvent, …)
  Event/           PaymentEvent (marker) + PaymentCharged, PaymentCaptured, PaymentRefunded, WebhookReceived, …
  ValueObject/     Money, Customer, BillingAddress, GatewayCredentials
  Enum/            GatewayName, PaymentStatus, CredentialInitiator
  Exception/       GatewayException + subtypes (UnsupportedOperation, WebhookVerification, …)

Infrastructure/  adapters for the ports — the only layer that touches Laravel & the network
  Gateway/{X}/     CybersourceUnifiedCheckout · Fawry · Paymob · Paylink · Paytabs · PayPal (extend AbstractPaymentGateway)
  Gateway/         EventDispatchingGateway (emits events) · LoggingGateway (logs each operation) — driver decorators
  Http/            HttpClient decorator stack: RetryingHttpClient → LoggingHttpClient → RateLimitingHttpClient → LaravelHttpClient · FakeHttpClient (tests)
  Events/          LaravelEventDispatcher · LoggingPaymentEventListener · RecordingEventDispatcher (tests)
  Support/         Value · Concerns\LogsAction (PSR-3 leveled logging + timing + masking)
  Credentials/     ConfigCredentialResolver
  Console/         ReconcileCommand (base) + one gateway:reconcile:{X} command per gateway
  GatewayServiceProvider (wires the ports + factory into the container, registers commands + the event listener)
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
