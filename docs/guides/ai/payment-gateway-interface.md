# PaymentGatewayInterface (AI reference)

The one contract every gateway driver implements, its abstract base class, and the "unsupported operations throw" rule. Request DTOs live in `Domain\Command\*`, result DTOs in `Domain\Result\*`.

## `PaymentGatewayInterface`

- Namespace: `Hyprpay\Payments\Domain\Contract`
- Purpose: The port every payment gateway driver in the SDK implements — the full set of payment operations (checkout, charge/capture, refund/void/reversal, 3-D Secure payer auth, vaulting + stored-credential charges, transaction lookup, webhook verification). Concrete adapters implement only what their gateway supports; the rest are inherited from `AbstractPaymentGateway`, which rejects them.

### Methods

| Method | Signature | Description |
|---|---|---|
| `name` | `name(): GatewayName` | Identify which gateway this driver represents (canonical identifier). |
| `credentials` | `credentials(): GatewayCredentials` | Expose the credentials the driver was constructed with. |
| `createCheckoutSession` | `createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession` | Create a hosted/embedded checkout session (token/redirect details) for the customer to complete payment. |
| `requestDccRate` | `requestDccRate(DccRateRequest $request): DccQuote` | Request a Dynamic Currency Conversion rate quote; the quote can be threaded into charge/capture/refund. Returns a quote or an "not offered" quote. |
| `charge` | `charge(ChargeRequest $request): PaymentResult` | Authorize (and optionally capture) a payment. |
| `capture` | `capture(CaptureRequest $request): PaymentResult` | Capture funds for a previously authorized payment. |
| `refund` | `refund(RefundRequest $request): RefundResult` | Refund all or part of a settled payment. |
| `void` | `void(VoidRequest $request): PaymentResult` | Void an authorized-but-not-yet-captured payment. |
| `reverseAuthorization` | `reverseAuthorization(ReversalRequest $request): PaymentResult` | Reverse an existing authorization, releasing held funds. |
| `setupPayerAuth` | `setupPayerAuth(PayerAuthSetupRequest $request): PayerAuthSetupResult` | Set up 3-D Secure (device data collection) before enrollment; result carries the device-data-collection URL, access token, and reference id. |
| `enrollPayerAuth` | `enrollPayerAuth(PayerAuthEnrollRequest $request): PayerAuthResult` | Begin 3-D Secure payer authentication by enrolling the card; result carries any challenge/redirect data. |
| `validatePayerAuth` | `validatePayerAuth(ValidatePayerAuthRequest $request): PayerAuthResult` | Complete 3-D Secure payer auth after challenge/validation; result carries authentication values for the subsequent charge. |
| `vaultInstrument` | `vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument` | Tokenize a payment instrument into the gateway vault for reuse. |
| `chargeStoredCredential` | `chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult` | Charge a stored (card-on-file) credential, honoring CIT/MIT rules. |
| `chargeWallet` | `chargeWallet(WalletChargeRequest $request): PaymentResult` | Charge a native digital-wallet token (Apple Pay / Google Pay); the gateway decrypts the forwarded token. |
| `getTransaction` | `getTransaction(string $transactionId): TransactionSnapshot` | Retrieve the current state of a transaction by its gateway identifier. |
| `searchTransaction` | `searchTransaction(string $query): ?TransactionSnapshot` | Search for a transaction via a gateway-specific query; returns `null` when nothing matches. |
| `findSuccessfulTransactionByReference` | `findSuccessfulTransactionByReference(string $reference): ?TransactionSnapshot` | Reconcile before a retry: the most recent settled (authorized/captured) transaction for a merchant reference, or `null`. |
| `listTransactions` | `listTransactions(string $query): array` | List every transaction matching a gateway-specific query, newest first (`list<TransactionSnapshot>`). |
| `listTransactionsByReference` | `listTransactionsByReference(string $reference): array` | List a payment's full history by merchant reference, newest first (`list<TransactionSnapshot>`). |
| `verifyWebhook` | `verifyWebhook(string $rawBody, array $headers): WebhookEvent` | Verify an inbound webhook's authenticity and parse it into an event. `$headers` is `array<string, string\|array<int, string>>`. |

Request → result mapping (which DTO maps to which return type):

- `CheckoutSessionRequest` → `CheckoutSession`
- `DccRateRequest` → `DccQuote`
- `ChargeRequest` / `CaptureRequest` / `VoidRequest` / `ReversalRequest` / `StoredCredentialChargeRequest` → `PaymentResult`
- `RefundRequest` → `RefundResult`
- `PayerAuthSetupRequest` → `PayerAuthSetupResult`
- `PayerAuthEnrollRequest` / `ValidatePayerAuthRequest` → `PayerAuthResult`
- `TokenizeInstrumentRequest` → `VaultedInstrument`
- `string $transactionId` → `TransactionSnapshot`
- `string $query` → `?TransactionSnapshot`
- `(string $rawBody, array $headers)` → `WebhookEvent`

## `AbstractPaymentGateway`

- Namespace: `Hyprpay\Payments\Domain`
- Declaration: `abstract class AbstractPaymentGateway implements PaymentGatewayInterface`
- Purpose: Base class for gateway drivers. Holds credentials and default-implements every operation to reject it with `UnsupportedOperationException`. Concrete gateways extend it, override only the operations they support, and must implement `name()`.

### Constructor

```php
public function __construct(protected readonly GatewayCredentials $gatewayCredentials)
```

Stores the credentials the driver uses to authenticate against the gateway.

### What it implements vs. defers

- `abstract public function name(): GatewayName` — **abstract**; each concrete driver MUST implement it to identify itself.
- `public function credentials(): GatewayCredentials` — **real implementation**; returns `$this->gatewayCredentials`.

### The "unsupported operations throw" contract

Every remaining `PaymentGatewayInterface` method has a default body that throws:

```php
throw UnsupportedOperationException::forOperation($this->name(), '<operation>');
```

`@throws UnsupportedOperationException` always, unless a concrete gateway overrides the method. The operation name passed to `forOperation` matches the method name. Methods that default-throw:

| Method | Operation string passed to `forOperation` |
|---|---|
| `createCheckoutSession` | `'createCheckoutSession'` |
| `requestDccRate` | `'requestDccRate'` |
| `charge` | `'charge'` |
| `capture` | `'capture'` |
| `refund` | `'refund'` |
| `void` | `'void'` |
| `reverseAuthorization` | `'reverseAuthorization'` |
| `setupPayerAuth` | `'setupPayerAuth'` |
| `enrollPayerAuth` | `'enrollPayerAuth'` |
| `validatePayerAuth` | `'validatePayerAuth'` |
| `vaultInstrument` | `'vaultInstrument'` |
| `chargeStoredCredential` | `'chargeStoredCredential'` |
| `chargeWallet` | `'chargeWallet'` |
| `getTransaction` | `'getTransaction'` |
| `searchTransaction` | `'searchTransaction'` |
| `findSuccessfulTransactionByReference` | `'findSuccessfulTransactionByReference'` |
| `listTransactions` | `'listTransactions'` |
| `listTransactionsByReference` | `'listTransactionsByReference'` |
| `verifyWebhook` | `'verifyWebhook'` |

So calling an operation a driver did not override raises `UnsupportedOperationException` (a subtype of `GatewayException`), whose message is `"The {label} gateway does not support the '{operation}' operation."`.

## Decorators over the interface

Both wrap any `PaymentGatewayInterface` (composition edge in `Infrastructure\Gateway`):

- `EventDispatchingGateway` — after each lifecycle operation returns, emits the matching `PaymentEvent` via the `EventDispatcher` port. See `events.md`.
- `LoggingGateway` — logs each operation. See `contracts-and-http.md` for the logging helpers.
