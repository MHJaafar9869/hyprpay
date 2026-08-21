# Request DTOs (Commands)

Reference for every input DTO under `Hyprpay\Payments\Domain\Command`, plus the `CheckoutOptions` interface. Each is a `final readonly class` with an all-`public` promoted-property constructor unless noted. Amounts are always a `Money` value object (see [value-objects.md](value-objects.md)).

## Checkout / session

### CheckoutSessionRequest
`Hyprpay\Payments\Domain\Command\CheckoutSessionRequest` — starts a checkout/payment session across gateways; a superset of CyberSource Unified Checkout fields and redirect-gateway (Fawry) fields. Each gateway reads only what it needs.

| param | type | default | meaning |
|---|---|---|---|
| `$money` | `Money` | — | Amount and currency for the session |
| `$targetOrigins` | `array<int,string>` | `[]` | UC: scheme + host of the page embedding the widget |
| `$allowedCardNetworks` | `array<int,string>` | `['VISA','MASTERCARD']` | UC: card brands to accept |
| `$allowedPaymentTypes` | `array<int,string>` | `['PANENTRY']` | UC: payment types (e.g. PANENTRY, GOOGLEPAY, APPLEPAY) |
| `$country` | `?string` | `null` | Two-letter ISO country code |
| `$locale` | `?string` | `null` | Locale for the widget/hosted UI (e.g. en_US) |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$enable3ds` | `bool` | `true` | UC: enable 3-D Secure payer authentication |
| `$billTo` | `?BillingAddress` | `null` | Billing address to prefill |
| `$clientVersion` | `string` | `'0.34'` | UC: version of the Unified Checkout client library to load |
| `$returnUrl` | `?string` | `null` | Redirect gateways (Fawry): URL to return the customer to after payment |
| `$customer` | `?Customer` | `null` | Customer profile (id/email/name) |
| `$paymentMethod` | `?string` | `null` | Gateway-specific method selector (e.g. Fawry: hosted, PayUsingCC, MWALLET, PAYATFAWRY) |
| `$description` | `?string` | `null` | Human-readable order/items description |
| `$options` | `?CheckoutOptions` | `null` | Gateway-specific extras as a typed per-gateway options DTO |
| `$completeMandate` | `?MandateCompletionType` | `null` | UC v1: when set, widget orchestrates the whole payment client-side and returns a signed result JWT (Capture=sale, Auth=auth hold). Null = manual transient-token flow |
| `$decisionManager` | `bool` | `true` | UC v1 orchestrated only: run Decision Manager (and device fingerprinting) as part of the mandate. Emitted as `completeMandate.decisionManager`; ignored unless `completeMandate` is set |

Public method:
- `optionsArray(): array<string,mixed>` — renders `$options?->toArray()` or `[]`.

### CheckoutOptions (interface)
`Hyprpay\Payments\Domain\Command\CheckoutOptions` — typed, gateway-specific bag of checkout options carried by `CheckoutSessionRequest` (replaces a free-form `array $options`). Concrete impls per gateway (e.g. `PayPalCheckoutOptions`, `PaytabsCheckoutOptions`).

Method:
- `toArray(): array<string,mixed>` — render the options as the gateway's raw option-key array.

## Card charge (Unified Checkout widget)

### ChargeRequest
`Hyprpay\Payments\Domain\Command\ChargeRequest` — charges a card captured by the Unified Checkout widget, carrying the transient token plus amount and optional billing/customer/3DS context.

| param | type | default | meaning |
|---|---|---|---|
| `$transientToken` | `string` | — | One-time token from the widget representing the entered card |
| `$money` | `Money` | — | Amount and currency to charge |
| `$capture` | `bool` | `true` | Capture immediately (true) or authorise only (false) |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$billTo` | `?BillingAddress` | `null` | Billing address for the payer |
| `$customer` | `?Customer` | `null` | Customer identity to associate |
| `$consumerAuthentication` | `array<string,mixed>` | `[]` | 3DS cryptogram fields (cavv, eci, xid, …) when authenticated |
| `$commerceIndicator` | `?string` | `null` | Commerce indicator overriding the default transaction type |
| `$deviceFingerprintId` | `?string` | `null` | Device fingerprint session id for fraud screening |
| `$idempotencyKey` | `?string` | `null` | Idempotency key; defaults to the order reference when omitted |
| `$dcc` | `?DccQuote` | `null` | DCC quote to bill the cardholder in their currency; set `money` to the quote's converted amount |
| `$useRawFingerprintSessionId` | `bool` | `false` | When true, CyberSource uses the fingerprint session id exactly as sent (no merchant-prefixed lookup) |

### StoredCredentialChargeRequest
`Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest` — charges a previously vaulted instrument (stored credential) for merchant- or customer-initiated transactions.

| param | type | default | meaning |
|---|---|---|---|
| `$paymentInstrumentId` | `string` | — | Vault id of the stored payment instrument to charge |
| `$money` | `Money` | — | Amount and currency to charge |
| `$initiator` | `CredentialInitiator` | `CredentialInitiator::Merchant` | Who initiated the charge (merchant/customer) |
| `$isFirstCharge` | `bool` | `false` | Whether this is the initial charge establishing the credential on file |
| `$customerId` | `?string` | `null` | Vault customer id owning the instrument |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$idempotencyKey` | `?string` | `null` | Idempotency key; defaults to the order reference when omitted |
| `$deviceFingerprintId` | `?string` | `null` | Device fingerprint session id for fraud screening |
| `$useRawFingerprintSessionId` | `bool` | `false` | When true, use the fingerprint session id exactly as sent |

### WalletChargeRequest
`Hyprpay\Payments\Domain\Command\WalletChargeRequest` — charges a native digital-wallet token (Apple Pay / Google Pay); the encrypted token is forwarded to the gateway to decrypt, so the SDK never handles the cleartext PAN.

| param | type | default | meaning |
|---|---|---|---|
| `$encryptedToken` | `string` | — | The wallet's device-encrypted token as delivered client-side (Apple Pay: `paymentData` serialized to JSON) |
| `$wallet` | `WalletType` | — | Which wallet produced the token (selects the gateway's payment-solution mapping) |
| `$money` | `Money` | — | Amount and currency to charge |
| `$capture` | `bool` | `true` | Capture immediately (true) or authorise only (false) |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$billTo` | `?BillingAddress` | `null` | Billing address for the payer |
| `$idempotencyKey` | `?string` | `null` | Idempotency key; defaults to the order reference when omitted |
| `$dcc` | `?DccQuote` | `null` | DCC quote to bill in the cardholder's currency |
| `$deviceFingerprintId` | `?string` | `null` | Device fingerprint session id for fraud screening |
| `$useRawFingerprintSessionId` | `bool` | `false` | When true, use the fingerprint session id exactly as sent |

### TokenizeInstrumentRequest
`Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest` — tokenises (vaults) a card into a reusable payment instrument, later charged via `StoredCredentialChargeRequest`. Supply either the raw card fields **or** a `$transientToken` (a browser-tokenised nonce such as Authorize.Net Accept.js opaque data) to vault without the PAN touching the server.

| param | type | default | meaning |
|---|---|---|---|
| `$cardNumber` | `string` | `''` | Primary account number (PAN); leave blank when vaulting from a transient token |
| `$expirationMonth` | `string` | `''` | Two-digit card expiry month (MM); blank when using a transient token |
| `$expirationYear` | `string` | `''` | Four-digit card expiry year (YYYY); blank when using a transient token |
| `$cardType` | `?string` | `null` | Gateway card-type code (e.g. Visa/Mastercard identifier) |
| `$billTo` | `?BillingAddress` | `null` | Billing address to store with the instrument |
| `$customerReference` | `?string` | `null` | Merchant customer reference to associate with the vaulted instrument |
| `$transientToken` | `?string` | `null` | Browser-tokenised payment nonce (e.g. Accept.js opaque data) to vault without handling the raw card |

## Transaction lifecycle (capture / void / refund / reversal)

### CaptureRequest
`Hyprpay\Payments\Domain\Command\CaptureRequest` — captures funds on a previously authorised transaction (full or partial settle).

| param | type | default | meaning |
|---|---|---|---|
| `$transactionId` | `string` | — | Identifier of the authorisation to capture |
| `$money` | `Money` | — | Amount and currency to capture (cardholder's billing currency for a DCC capture) |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$idempotencyKey` | `?string` | `null` | Idempotency key; supply a unique value per capture (partial captures need distinct keys) |
| `$dcc` | `?DccQuote` | `null` | DCC quote from the authorization, to capture at the same quoted rate |

### VoidRequest
`Hyprpay\Payments\Domain\Command\VoidRequest` — voids an uncaptured transaction (cancel a capture/credit that has not settled).

| param | type | default | meaning |
|---|---|---|---|
| `$transactionId` | `string` | — | Identifier of the transaction to void |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$idempotencyKey` | `?string` | `null` | Idempotency key so a retried void is not double-processed |

### ReversalRequest
`Hyprpay\Payments\Domain\Command\ReversalRequest` — reverses (releases) a prior authorisation hold before capture.

| param | type | default | meaning |
|---|---|---|---|
| `$transactionId` | `string` | — | Identifier of the authorisation to reverse |
| `$money` | `Money` | — | Amount and currency to reverse (cardholder's billing currency for a DCC reversal) |
| `$reason` | `?string` | `null` | Reason for the reversal |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$idempotencyKey` | `?string` | `null` | Idempotency key so a retried reversal is not double-processed |
| `$dcc` | `?DccQuote` | `null` | DCC quote from the authorization; marks the reversal amount as the cardholder's billing currency |

### RefundRequest
`Hyprpay\Payments\Domain\Command\RefundRequest` — refunds funds from a settled/captured transaction (full or partial).

| param | type | default | meaning |
|---|---|---|---|
| `$transactionId` | `string` | — | Identifier of the captured transaction to refund |
| `$money` | `Money` | — | Amount and currency to refund (cardholder's billing currency for a DCC refund) |
| `$reason` | `?string` | `null` | Reason for the refund |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$idempotencyKey` | `?string` | `null` | Idempotency key; supply a unique value per refund (partial refunds need distinct keys) |
| `$dcc` | `?DccQuote` | `null` | DCC quote from the original charge, to refund at the same quoted rate |

## Dynamic Currency Conversion (DCC)

### DccRateRequest
`Hyprpay\Payments\Domain\Command\DccRateRequest` — requests a DCC rate quote; returns a `DccQuote` that can be threaded into charge/capture/refund.

| param | type | default | meaning |
|---|---|---|---|
| `$money` | `Money` | — | Original amount and merchant currency to convert from |
| `$cardNumber` | `string` | — | Card number (PAN) whose BIN determines the cardholder's currency |
| `$orderReference` | `?string` | `null` | Merchant reference for correlation |

## 3-D Secure payer authentication

### PayerAuthEnrollRequest
`Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest` — enrols a card into 3-D Secure; the resulting `PayerAuthResult` indicates whether a challenge (step-up) is required.

| param | type | default | meaning |
|---|---|---|---|
| `$transientToken` | `string` | — | One-time widget token representing the card |
| `$money` | `Money` | — | Amount and currency being authenticated |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$returnUrl` | `?string` | `null` | URL the issuer redirects back to after a 3DS challenge |
| `$referenceId` | `?string` | `null` | Device-data-collection reference id from setup |
| `$billTo` | `?BillingAddress` | `null` | Billing address for the payer |
| `$deviceFingerprintId` | `?string` | `null` | Device fingerprint session id for fraud screening |
| `$useRawFingerprintSessionId` | `bool` | `false` | When true, use the fingerprint session id exactly as sent |

### ValidatePayerAuthRequest
`Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest` — validates a 3-D Secure authentication after a challenge, using the auth transaction id from the enrol step to fetch the final cryptogram.

| param | type | default | meaning |
|---|---|---|---|
| `$authenticationTransactionId` | `string` | — | Identifier of the authentication to validate (from the enrol step) |
| `$money` | `Money` | — | Amount and currency being authenticated |
| `$transientToken` | `?string` | `null` | Unified Checkout transient token for the card |
| `$orderReference` | `?string` | `null` | Merchant order/reference for reconciliation |
| `$device` | `?BrowserDeviceData` | `null` | 3-D Secure browser device data (user agent, browser details, IP) to improve frictionless/risk-based auth |

## Orchestrated (autoProcessing) payment confirmation

### ConfirmOrchestratedPaymentRequest
`Hyprpay\Payments\Domain\Command\ConfirmOrchestratedPaymentRequest` — confirms a Unified Checkout v1 orchestrated payment. Carries the signed result JWT plus the capture-context JWT (source of the verification key) and the values the result must match. The gateway verifies signature and validates against these; no server-side authorization call is made.

| param | type | default | meaning |
|---|---|---|---|
| `$resultJwt` | `string` | — | Signed completed-payment result JWT returned by `checkout.mount()` |
| `$captureContextJwt` | `string` | — | Capture-context JWT the session was created with; carries the embedded verification key |
| `$expectedMoney` | `Money` | — | Amount and currency the result must match (rejected on mismatch) |
| `$orderReference` | `?string` | `null` | Merchant order reference the result must match when supplied |
| `$expectedIssuer` | `?string` | `null` | Issuer (`iss`) claim the result must carry when supplied |
| `$leewaySeconds` | `int` | `60` | Clock-skew allowance in seconds applied to exp/iat/nbf validation |
