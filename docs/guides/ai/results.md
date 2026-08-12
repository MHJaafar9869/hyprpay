# Result DTOs

Reference for every output DTO under `Hyprpay\Payments\Domain\Result`. Each is a `final readonly class` with an all-`public` promoted-property constructor. `PaymentStatus` values are documented in [enums.md](enums.md); `Money` in [value-objects.md](value-objects.md). Only `PayerAuthResult` carries a helper method — the rest are pure data.

## PaymentResult
`Hyprpay\Payments\Domain\Result\PaymentResult` — normalised outcome of a charge, capture, void, or reversal.

| field | type | default | meaning |
|---|---|---|---|
| `$success` | `bool` | — | Whether the payment operation succeeded |
| `$status` | `PaymentStatus` | — | Normalised payment status enum |
| `$transactionId` | `?string` | `null` | Gateway transaction identifier |
| `$code` | `?string` | `null` | Gateway response/status code |
| `$message` | `?string` | `null` | Human-readable gateway response message |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

No public methods.

## RefundResult
`Hyprpay\Payments\Domain\Result\RefundResult` — normalised outcome of a refund operation.

| field | type | default | meaning |
|---|---|---|---|
| `$success` | `bool` | — | Whether the refund succeeded |
| `$status` | `PaymentStatus` | — | Normalised payment status enum for the refund |
| `$refundId` | `?string` | `null` | Gateway identifier for the created refund |
| `$code` | `?string` | `null` | Gateway response/status code |
| `$message` | `?string` | `null` | Human-readable gateway response message |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

No public methods.

## TransactionSnapshot
`Hyprpay\Payments\Domain\Result\TransactionSnapshot` — current state of a transaction as fetched from the gateway (lookup/reconciliation).

| field | type | default | meaning |
|---|---|---|---|
| `$transactionId` | `string` | — | Gateway transaction identifier being described |
| `$status` | `PaymentStatus` | — | Normalised current status of the transaction |
| `$money` | `?Money` | `null` | Amount and currency, when known |
| `$orderReference` | `?string` | `null` | Merchant order/reference number, when known |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

No public methods.

## CheckoutSession
`Hyprpay\Payments\Domain\Result\CheckoutSession` — gateway-agnostic result of a create-checkout-session call describing how the customer completes the payment. Different rails populate different fields (UC returns a JWT + client library; Fawry returns redirect URL / reference / QR). Only relevant fields are set.

| field | type | default | meaning |
|---|---|---|---|
| `$jwt` | `?string` | `null` | Signed capture-context JWT initialising a widget checkout (e.g. CyberSource UC) |
| `$clientLibrary` | `?string` | `null` | URL of the widget's JS client library, when applicable |
| `$clientLibraryIntegrity` | `?string` | `null` | Subresource-integrity hash for the client library script |
| `$redirectUrl` | `?string` | `null` | URL to redirect the customer to (hosted checkout or 3DS step-up) |
| `$reference` | `?string` | `null` | Gateway reference the customer uses to pay (e.g. Fawry reference number) |
| `$qrCode` | `?string` | `null` | QR payload/image the customer scans to pay (e.g. wallet QR) |
| `$merchantReference` | `?string` | `null` | Merchant reference (order number) associated with the session |
| `$raw` | `array<string,mixed>` | `[]` | Raw decoded gateway response/claims, when available |

No public methods.

## VaultedInstrument
`Hyprpay\Payments\Domain\Result\VaultedInstrument` — returned after tokenising (vaulting) a card, exposing the vault identifiers created.

| field | type | default | meaning |
|---|---|---|---|
| `$success` | `bool` | — | Whether the instrument was vaulted successfully |
| `$instrumentIdentifierId` | `?string` | `null` | Vault identifier for the underlying card (instrument identifier) |
| `$customerId` | `?string` | `null` | Vault customer identifier the instrument was stored under |
| `$paymentInstrumentId` | `?string` | `null` | Vault payment-instrument identifier used to charge the stored credential |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

No public methods.

## PayerAuthResult
`Hyprpay\Payments\Domain\Result\PayerAuthResult` — returned by a 3-D Secure enrol or validate operation; reports whether auth succeeded and, when the issuer demands a challenge, the step-up URL/token to render it plus the normalised cryptogram fields.

| field | type | default | meaning |
|---|---|---|---|
| `$success` | `bool` | — | Whether the authentication step completed successfully |
| `$status` | `string` | — | Gateway auth status (e.g. AUTHENTICATION_SUCCESSFUL, PENDING_AUTHENTICATION) |
| `$stepUpUrl` | `?string` | `null` | Issuer challenge (step-up) URL to redirect the payer to, when a challenge is required |
| `$accessToken` | `?string` | `null` | JWT posted to the step-up URL to launch the challenge |
| `$authenticationTransactionId` | `?string` | `null` | Identifier used to validate the authentication after a challenge |
| `$consumerAuthenticationInformation` | `array<string,mixed>` | `[]` | Normalised 3DS cryptogram fields |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

Public method:
- `requiresChallenge(): bool` — true when a step-up URL is present (payer must complete the challenge before the charge can proceed).

## DccQuote
`Hyprpay\Payments\Domain\Result\DccQuote` — a Dynamic Currency Conversion rate quote from the gateway's rate-inquiry. Pass back into a `ChargeRequest` (with `money` = `$convertedAmount`) so the same rate id is echoed on auth/capture/refund.

| field | type | default | meaning |
|---|---|---|---|
| `$offered` | `bool` | — | Whether the gateway offered DCC for this card and amount (a rate was quoted) |
| `$id` | `?string` | `null` | Quoted rate id, echoed as the currency-conversion id on DCC auth/capture/refund |
| `$exchangeRate` | `?string` | `null` | Quoted exchange rate as an exact decimal string (e.g. "48.00") |
| `$originalAmount` | `?Money` | `null` | Amount in the merchant's currency, as sent |
| `$convertedAmount` | `?Money` | `null` | Amount in the cardholder's billing currency at the quoted rate |
| `$exchangeRateTimeStamp` | `?string` | `null` | Gateway timestamp identifying when the rate was quoted |
| `$raw` | `array<string,mixed>` | `[]` | Raw decoded gateway response |

No public methods.

## OrchestratedPaymentResult
`Hyprpay\Payments\Domain\Result\OrchestratedPaymentResult` — outcome of a verified Unified Checkout v1 orchestrated (autoProcessing) payment, returned once the result JWT is cryptographically verified and validated against the order. Carries reusable TMS token ids + network transaction id for stored-credential reuse, plus card display metadata. For wallet payments (Apple/Google Pay) no reusable credential is issued: `$isWallet` is true and the token ids are null.

| field | type | default | meaning |
|---|---|---|---|
| `$success` | `bool` | — | Whether the verified status represents a successful outcome |
| `$status` | `PaymentStatus` | — | Normalised payment status mapped from the result JWT |
| `$transactionId` | `?string` | `null` | CyberSource transaction id from the result JWT |
| `$orderReference` | `?string` | `null` | Merchant order reference carried by the result JWT |
| `$networkTransactionId` | `?string` | `null` | Network/processor transaction id for stored-credential reuse |
| `$isWallet` | `bool` | `false` | True for Apple Pay / Google Pay results, which yield no reusable credential |
| `$instrumentIdentifierId` | `?string` | `null` | TMS instrument identifier id (null for wallet payments) |
| `$paymentInstrumentId` | `?string` | `null` | TMS payment instrument id (null for wallet payments) |
| `$customerId` | `?string` | `null` | TMS customer/token id (null for wallet payments) |
| `$cardBrand` | `?string` | `null` | Card brand (e.g. visa, mastercard) when present |
| `$cardLast4` | `?string` | `null` | Last four digits of the card when present |
| `$cardExpiryMonth` | `?string` | `null` | Card expiry month when present |
| `$cardExpiryYear` | `?string` | `null` | Card expiry year when present |
| `$raw` | `array<string,mixed>` | `[]` | The verified result JWT claims |

No public methods.

## WebhookEvent
`Hyprpay\Payments\Domain\Result\WebhookEvent` — a parsed and signature-verified inbound gateway webhook.

| field | type | default | meaning |
|---|---|---|---|
| `$verified` | `bool` | — | Whether the webhook signature was successfully verified |
| `$eventType` | `?string` | `null` | Gateway event type/name carried by the notification |
| `$transactionId` | `?string` | `null` | Gateway transaction identifier the event relates to |
| `$status` | `?PaymentStatus` | `null` | Normalised payment status conveyed by the event, when applicable |
| `$payload` | `array<string,mixed>` | `[]` | Raw decoded webhook payload |

No public methods.
