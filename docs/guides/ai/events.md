# Payment Events (AI reference)

The `PaymentEvent` marker + every concrete event, its fields, and when it fires. All live in `Hyprpay\Payments\Domain\Event`. Events are emitted by `EventDispatchingGateway` (`Infrastructure\Gateway`) through the `EventDispatcher` port.

## When events fire

`EventDispatchingGateway` wraps any `PaymentGatewayInterface`. After the inner driver returns from a lifecycle operation, it constructs the matching event and calls `EventDispatcher::dispatch(...)`, then returns the inner result. Key semantics:

- Events fire **on completion regardless of success** — the result's `success`/`status` carries the outcome, so listeners can react to declines too.
- If the inner call **throws**, the exception propagates and **no event is dispatched**.
- Read/query operations are **pass-through, no event**: `requestDccRate`, `enrollPayerAuth`, `validatePayerAuth`, `getTransaction`, `searchTransaction`, plus the identity accessors `name()` / `credentials()`.

Operation → event map (as dispatched in `EventDispatchingGateway`):

| Operation | Event | Event constructor args (in order) |
|---|---|---|
| `createCheckoutSession` | `CheckoutSessionCreated` | `name(), $request->orderReference, $request->money, $session` |
| `charge` | `PaymentCharged` | `name(), $request->orderReference, $request->money, $result` |
| `capture` | `PaymentCaptured` | `name(), $request->transactionId, $request->orderReference, $request->money, $result` |
| `refund` | `PaymentRefunded` | `name(), $request->transactionId, $request->orderReference, $request->money, $result` |
| `void` | `PaymentVoided` | `name(), $request->transactionId, $request->orderReference, $result` |
| `reverseAuthorization` | `AuthorizationReversed` | `name(), $request->transactionId, $request->orderReference, $request->money, $result` |
| `vaultInstrument` | `InstrumentVaulted` | `name(), $request->customerReference, $result` |
| `chargeStoredCredential` | `StoredCredentialCharged` | `name(), $request->paymentInstrumentId, $request->orderReference, $request->money, $result` |
| `chargeWallet` | `WalletCharged` | `name(), $request->wallet, $request->orderReference, $request->money, $result` |
| `verifyWebhook` | `WebhookReceived` | `name(), $event` |

## `PaymentEvent` (interface / marker)

- `interface PaymentEvent`
- Purpose: marker implemented by every payment domain event. A listener can subscribe to this interface to receive **every** event (Laravel routes interface listeners to all implementing events), or target a concrete type for one operation.
- Events are queue-safe: they carry only non-sensitive identifiers, the amount, and the normalized result — never the raw request (which can hold a PAN).
- Method:
  - `gateway(): GatewayName` — the gateway that produced this event.

Every concrete event below is a `final readonly class ... implements PaymentEvent` and implements `gateway(): GatewayName` returning its `$gateway` field.

## `AuthorizationReversed`

Fires after an existing authorization is reversed (held funds released).

```php
__construct(
    public GatewayName $gateway,       // gateway that ran the reversal
    public string $transactionId,      // the authorization being reversed
    public ?string $orderReference,    // merchant order ref for correlation
    public Money $money,               // amount + currency released
    public PaymentResult $result,      // normalized outcome
)
```

## `CheckoutSessionCreated`

Fires after a checkout session is created for the customer to complete payment.

```php
__construct(
    public GatewayName $gateway,       // gateway that created the session
    public ?string $orderReference,    // merchant order ref for correlation
    public Money $money,               // amount + currency of the checkout
    public CheckoutSession $session,   // created session (redirect URL, reference, …)
)
```

## `InstrumentVaulted`

Fires after a payment instrument is tokenized (vaulted) for later reuse.

```php
__construct(
    public GatewayName $gateway,          // gateway that vaulted the instrument
    public ?string $customerReference,    // merchant customer ref the instrument belongs to
    public VaultedInstrument $result,     // stored instrument identifiers (token, customer id)
)
```

## `PaymentCaptured`

Fires after a capture of a previously authorized payment completes.

```php
__construct(
    public GatewayName $gateway,       // gateway that ran the capture
    public string $transactionId,      // the authorization being captured
    public ?string $orderReference,    // merchant order ref for correlation
    public Money $money,               // amount + currency captured
    public PaymentResult $result,      // normalized outcome
)
```

## `PaymentCharged`

Fires after a charge completes; inspect `result`'s `success`/`status` for the outcome.

```php
__construct(
    public GatewayName $gateway,       // gateway that ran the charge
    public ?string $orderReference,    // merchant order ref for correlation
    public Money $money,               // amount + currency charged
    public PaymentResult $result,      // normalized outcome
)
```

## `PaymentRefunded`

Fires after a refund of a settled payment completes.

```php
__construct(
    public GatewayName $gateway,       // gateway that ran the refund
    public string $transactionId,      // the captured transaction being refunded
    public ?string $orderReference,    // merchant order ref for correlation
    public Money $money,               // amount + currency refunded
    public RefundResult $result,       // normalized outcome
)
```

## `PaymentVoided`

Fires after an authorized-but-uncaptured payment is voided. (Note: no `Money` field.)

```php
__construct(
    public GatewayName $gateway,       // gateway that ran the void
    public string $transactionId,      // the transaction being voided
    public ?string $orderReference,    // merchant order ref for correlation
    public PaymentResult $result,      // normalized outcome
)
```

## `StoredCredentialCharged`

Fires after a charge against a stored (vaulted) credential completes.

```php
__construct(
    public GatewayName $gateway,           // gateway that ran the charge
    public string $paymentInstrumentId,    // vaulted instrument token that was charged
    public ?string $orderReference,        // merchant order ref for correlation
    public Money $money,                   // amount + currency charged
    public PaymentResult $result,          // normalized outcome
)
```

## `WalletCharged`

Fires after a digital-wallet charge (Apple Pay / Google Pay) completes.

```php
__construct(
    public GatewayName $gateway,      // gateway that ran the charge
    public WalletType $wallet,        // wallet whose token was charged
    public ?string $orderReference,   // merchant order ref for correlation
    public Money $money,              // amount + currency charged
    public PaymentResult $result,     // normalized outcome
)
```

## `WebhookReceived`

Fires after an inbound webhook is verified and parsed. The webhook's `WebhookEvent::$verified` flag tells whether the signature checked out; listeners should ignore unverified notifications. (Note: no `Money`/`orderReference` fields.)

```php
__construct(
    public GatewayName $gateway,       // gateway that sent the webhook
    public WebhookEvent $webhook,      // verified and parsed webhook event
)
```
