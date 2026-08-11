# Payment operations

[← Back to the README](../../README.md)

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

## Device fingerprinting (Decision Manager)

CyberSource Decision Manager profiles the shopper's device so a transaction can be
fraud-screened. Integration has **two halves**: a profiling tag you embed on the checkout
page (collects the device data), and a session id you send on the API request (tells
Decision Manager which profiled device the transaction belongs to). The SDK owns the
second half — the browser tag is yours to embed, because this package never renders HTML.

### 1. Embed the profiling tag on your checkout page

Add the `tags.js` script and its `<noscript>` fallback immediately above the closing
`</body>` tag of your checkout page. Give the profiling code 3–5 seconds to run before the
shopper submits the order. `session_id` is your **merchant id concatenated with a unique
session id** (`<merchant id><session id>`, no separator):

```html
<script type="text/javascript"
  src="https://h.online-metrix.net/fp/tags.js?org_id=<org id>&session_id=<merchant id><session id>"></script>
<noscript>
  <iframe style="width: 100px; height: 100px; border: 0; position: absolute; top: -5000px;"
    src="https://h.online-metrix.net/fp/tags?org_id=<org id>&session_id=<merchant id><session id>"></iframe>
</noscript>
```

- `org_id` — CyberSource's standard Decision Manager profiling org id (a shared value, not a
  per-merchant secret): **`1snn5n9w`** in test, **`k8vif92e`** in production. Confirm yours
  with your CyberSource representative. Because the SDK is backend-only and never renders the
  tag, the org id lives in your checkout page, not in this package's config.
- `<session id>` — a per-page-load unique string, max **88 characters**, using only
  letters, digits, hyphens, and underscores (`[A-Za-z0-9_-]`). A fresh `crypto.randomUUID()`
  per page load is ideal; reuse across page loads breaks profiling.
- `tags.js` supersedes the legacy `check.js`. For production, serve the tag from a local
  URL that your web server redirects to `h.online-metrix.net`, so the fingerprint host is
  not visible in the address bar.

In practice you pick the org id by test mode, build `session_id` as `merchant id + a fresh
UUID`, inject the tag, and keep the UUID to send to your server:

```html
<script>
  const orgId      = isTestMode ? '1snn5n9w' : 'k8vif92e';
  const merchantId = '<your CyberSource merchant id>';
  const sessionId  = crypto.randomUUID();               // the <session id>; send THIS to the API
  const tag        = document.createElement('script');
  tag.src = 'https://h.online-metrix.net/fp/tags.js?org_id=' + orgId +
            '&session_id=' + encodeURIComponent(merchantId + sessionId);
  document.head.appendChild(tag);
</script>
```

### 2. Send the session id on the API request

On the request, send **only the `<session id>` part** — *not* the merchant-prefixed value
from the tag. Pass it as `deviceFingerprintId`; it maps to
`deviceInformation.fingerprintSessionId`. It is accepted on `charge`,
`chargeStoredCredential`, and `enrollPayerAuth`:

```php
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;

$result = $cybersource->charge(new ChargeRequest(
    transientToken: $tokenFromWidget,
    money: Money::minor(10000, 'EGP'),
    orderReference: 'ORDER-123',
    deviceFingerprintId: $sessionId, // the UUID from the tag (the <session id> part), NOT merchantId + UUID
));
```

Set `useRawFingerprintSessionId: true` only if you sent the session id to the tag **without**
the merchant-id prefix — it tells CyberSource to look the device up by the raw session id
instead of re-prefixing it with your merchant id. For the standard tag above
(`session_id=<merchant id><session id>`), leave it at its default `false`.

### Orchestrated flow (completeMandate)

The steps above apply to the **manual transient-token flow**, where you call `charge` /
`chargeStoredCredential` / `enrollPayerAuth` server-side. The **orchestrated flow**
(`CheckoutSessionRequest` with a `completeMandate`) has no server-side payments call — the
Unified Checkout widget runs Decision Manager, 3DS, authorization, and TMS itself and
collects the device data on its own. There is no `fingerprintSessionId` to pass there;
instead Decision Manager (device fingerprinting included) is toggled by
`completeMandate.decisionManager`, which the SDK emits from `CheckoutSessionRequest`:

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Enum\MandateCompletionType;
use Hyprpay\Payments\Domain\ValueObject\Money;

$session = $cybersource->createCheckoutSession(new CheckoutSessionRequest(
    money: Money::minor(10000, 'EGP'),
    targetOrigins: ['https://shop.test'],
    completeMandate: MandateCompletionType::Capture, // orchestrate the whole payment
    // decisionManager defaults to true — pass false to skip Decision Manager
));
```

`decisionManager` defaults to `true`, so orchestrated sales are fraud-screened out of the
box; set it to `false` to opt out. It is ignored unless `completeMandate` is set.

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
| Mastercard MPGS | order id + transaction id in the request URL (re-PUT is idempotent) |

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
php artisan gateway:reconcile:mpgs ORDER-128             # an MPGS order id
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

