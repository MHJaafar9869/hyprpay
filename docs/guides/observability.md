# Events & operation logging

[← Back to the README](../../README.md)

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

