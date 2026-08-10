# Architecture

[← Back to the README](../../README.md)

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
  Gateway/{X}/     CybersourceUnifiedCheckout · Fawry · Paymob · Paylink · Paytabs · PayPal · Mpgs (extend AbstractPaymentGateway)
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

