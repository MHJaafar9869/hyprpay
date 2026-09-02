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

## SubscriptionResult
`Hyprpay\Payments\Domain\Result\SubscriptionResult` — a recurring subscription after a create, lookup, or lifecycle call; the same shape reports the subscription's standing whether it was just opened, fetched, cancelled, suspended, or reactivated. `$status` is the subscription's own lifecycle state and `$requestStatus` the gateway's verdict on the call that produced it — a create commonly returns `requestStatus` COMPLETED with `status` Pending, because the subscription has not reached its first billing date yet. A lookup carries no request status at all.

| field | type | default | meaning |
|---|---|---|---|
| `$success` | `bool` | — | Whether the subscription operation succeeded |
| `$status` | `?SubscriptionStatus` | `null` | Normalised lifecycle status of the subscription, when the gateway reported one |
| `$subscriptionId` | `?string` | `null` | Gateway identifier for the subscription, used by the lifecycle operations |
| `$subscriptionCode` | `?string` | `null` | Subscription code — merchant-assigned or gateway-generated |
| `$planId` | `?string` | `null` | Identifier of the billing plan the subscription follows, when it references one |
| `$name` | `?string` | `null` | Human-readable subscription name |
| `$startDate` | `?string` | `null` | Date of the first charge as the gateway records it (UTC `YYYY-MM-DDThh:mm:ssZ`) |
| `$orderReference` | `?string` | `null` | Merchant order/reference number carried on the subscription |
| `$requestStatus` | `?string` | `null` | Raw gateway status for the request itself (COMPLETED, ACCEPTED, DECLINED); null on a lookup |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload (CyberSource's `reactivationInformation` block lands here) |

No public methods.

## SubscriptionPage
`Hyprpay\Payments\Domain\Result\SubscriptionPage` — one page of subscriptions from a list call. Carries the page's records alongside the total the filter matched and the window that produced them, so "20 of 340" is distinguishable from "the last 20".

| field | type | default | meaning |
|---|---|---|---|
| `$subscriptions` | `list<SubscriptionResult>` | — | The subscriptions on this page, in gateway order |
| `$totalCount` | `int` | `0` | Total matching the filter across every page |
| `$offset` | `int` | `0` | Records skipped before this page |
| `$limit` | `int` | `0` | Page size the gateway was asked for |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

Public methods:
- `isEmpty(): bool` — true when the page returned no subscriptions.
- `count(): int` — how many subscriptions this page holds.
- `hasMore(): bool` — true when more matched the filter than this page returned. False on an empty page, so a walk always terminates even if the gateway reports a total larger than the records it can serve.

## Report
`Hyprpay\Payments\Domain\Result\Report` — one generated (or still generating) gateway report. Generation is asynchronous, so a report existing is not the same as its file existing.

| field | type | default | meaning |
|---|---|---|---|
| `$reportId` | `?string` | `null` | Gateway identifier for this report run |
| `$name` | `?string` | `null` | Report name, as assigned at creation |
| `$definitionId` | `?string` | `null` | Report definition this run was produced from |
| `$status` | `?ReportStatus` | `null` | Normalised generation status |
| `$frequency` | `?ReportFrequency` | `null` | Cadence this report was produced at |
| `$format` | `?ReportFormat` | `null` | File format the report was generated in |
| `$startTime` | `?string` | `null` | Start of the period covered (UTC ISO 8601) |
| `$endTime` | `?string` | `null` | End of the period covered (UTC ISO 8601) |
| `$completedTime` | `?string` | `null` | When generation finished (UTC ISO 8601) |
| `$timezone` | `?string` | `null` | Timezone the report's times are expressed in |
| `$organizationId` | `?string` | `null` | Organization the report belongs to |
| `$subscriptionType` | `?string` | `null` | Custom, Standard, or Classic |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

Public methods:
- `isDownloadable(): bool` — true only when the status is ready; a report with no status is not assumed ready.
- `downloadRequest(): ?DownloadReportRequest` — the correct download request for this report, or null when it is not downloadable. Applies the report-date rule (the **end** of the period covered, truncated to `YYYY-MM-DD`) and carries the format the report was generated in, so the name/date/format do not have to be assembled by hand.

## ReportFile
`Hyprpay\Payments\Domain\Result\ReportFile` — a downloaded report file. The gateway returns CSV or XML rather than JSON, so the content is carried verbatim and deliberately not parsed: a report's columns depend on its definition and the fields requested.

| field | type | default | meaning |
|---|---|---|---|
| `$content` | `string` | — | The report file exactly as the gateway returned it |
| `$format` | `ReportFormat` | — | Format the content is in |
| `$name` | `string` | — | Name the report was downloaded by |
| `$reportDate` | `string` | — | Report date the download was keyed on (`YYYY-MM-DD`) |

Public methods:
- `isEmpty(): bool` — true when the gateway returned an empty file (a run that matched no rows).
- `bytes(): int` — size of the downloaded file.
- `filename(): string` — conventional filename, e.g. `settlement-sept-2026-09-02.csv`.

## ReportSubscription
`Hyprpay\Payments\Domain\Result\ReportSubscription` — a standing schedule that makes the gateway generate a report repeatedly. Distinct from `Report`, which is one run the schedule produced.

| field | type | default | meaning |
|---|---|---|---|
| `$name` | `?string` | `null` | Unique subscription name, and the key its runs are downloaded by |
| `$definitionName` | `?string` | `null` | Report definition the schedule runs |
| `$frequency` | `?ReportFrequency` | `null` | How often the report is generated |
| `$format` | `?ReportFormat` | `null` | File format each run is generated in |
| `$startTime` | `?string` | `null` | Time of day each run starts, as `hhmm` |
| `$startDay` | `?int` | `null` | Day the schedule runs on for a weekly or monthly cadence |
| `$timezone` | `?string` | `null` | Timezone the schedule runs in |
| `$fields` | `array<int,string>` | `[]` | Columns included in each generated report |
| `$subscriptionType` | `?string` | `null` | Custom, Standard, or Classic |
| `$organizationId` | `?string` | `null` | Organization the subscription belongs to |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

No public methods.

## BankAccountValidationResult
`Hyprpay\Payments\Domain\Result\BankAccountValidationResult` — outcome of a Visa Bank Account Validation (BAVS) check. Two codes answer different questions: `$resultCode` is the verdict on the account, `$rawValidationCode` says whether the check could run at all.

| field | type | default | meaning |
|---|---|---|---|
| `$resultCode` | `?int` | `null` | Verdict on the account; `0` is the only documented pass (others: `4`, `98`, `99`) |
| `$rawValidationCode` | `?int` | `null` | Whether the check ran: `-1` unknown error, `-2` service unavailable, `12`-`16` validation results |
| `$resultMessage` | `?string` | `null` | Human-readable result message |
| `$requestId` | `?string` | `null` | Gateway identifier for the validation request |
| `$submitTimeUtc` | `?string` | `null` | When the request was processed (UTC) |
| `$orderReference` | `?string` | `null` | Merchant reference the validation was sent with |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

Constants: `RESULT_VALID` (`0`), `RAW_UNKNOWN_ERROR` (`-1`), `RAW_SERVICE_UNAVAILABLE` (`-2`).

Public methods:
- `isValid(): bool` — deliberately strict: only the documented pass code counts, so an unrecognised outcome never reads as a validated account.
- `isInconclusive(): bool` — true when the check could not be completed (`-1`/`-2`). Retry these; they are **not** evidence the account is bad, so rejecting the customer's details on one would be wrong.

## PaymentInstrument
`Hyprpay\Payments\Domain\Result\PaymentInstrument` — a vaulted payment instrument as the vault currently holds it. Where `VaultedInstrument` reports the ids produced by tokenising a card, this is the stored record read back — which is what lets a dead card be caught at rest rather than at charge time.

| field | type | default | meaning |
|---|---|---|---|
| `$id` | `?string` | `null` | Vault payment-instrument identifier |
| `$customerId` | `?string` | `null` | Vault customer it is stored under |
| `$instrumentIdentifierId` | `?string` | `null` | Vault identifier for the underlying card |
| `$state` | `?PaymentInstrumentState` | `null` | Issuer's standing (active or closed) |
| `$isDefault` | `bool` | `false` | Whether this is the customer's default instrument |
| `$expirationMonth` | `?string` | `null` | Two-digit expiry month (`MM`) |
| `$expirationYear` | `?string` | `null` | Four-digit expiry year (`YYYY`) |
| `$cardType` | `?string` | `null` | Gateway card-network code |
| `$maskedNumber` | `?string` | `null` | Masked number from the linked instrument identifier |
| `$billTo` | `array<string,mixed>` | `[]` | Billing address stored with the instrument |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

Public methods:
- `expiry(): ?string` — the stored expiry as `MM/YYYY`, or null when either part is missing.
- `isExpired(?int $timestamp = null): bool` — whether the stored expiry is in the past. Pure, no network call. Returns false when the vault reported no usable expiry — an unknown date is never treated as expired.

## PaymentInstrumentPage
`Hyprpay\Payments\Domain\Result\PaymentInstrumentPage` — one page of a customer's vaulted instruments (20 default, 100 max).

| field | type | default | meaning |
|---|---|---|---|
| `$instruments` | `list<PaymentInstrument>` | — | The instruments on this page |
| `$totalCount` | `int` | `0` | Total the customer holds across every page |
| `$offset` | `int` | `0` | Records skipped before this page |
| `$limit` | `int` | `0` | Page size requested |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

Public methods: `isEmpty()`, `count()`, `hasMore()` (false on an empty page, so a walk always terminates), and `default(): ?PaymentInstrument` — the instrument payments fall back to.

## PlanResult
`Hyprpay\Payments\Domain\Result\PlanResult` — a recurring billing plan after a create, lookup, or lifecycle call. As with `SubscriptionResult`, `$status` is the plan's own state and `$requestStatus` the gateway's verdict on the call.

| field | type | default | meaning |
|---|---|---|---|
| `$success` | `bool` | — | Whether the plan operation succeeded |
| `$status` | `?PlanStatus` | `null` | Normalised lifecycle status |
| `$planId` | `?string` | `null` | Plan id, referenced when creating subscriptions |
| `$code` | `?string` | `null` | Plan code — merchant-assigned or gateway-generated |
| `$name` | `?string` | `null` | Plan name |
| `$description` | `?string` | `null` | Plan description |
| `$billingPeriod` | `?BillingPeriod` | `null` | Cadence subscriptions on this plan charge at |
| `$billingCycles` | `?int` | `null` | Cycles a subscription on this plan bills |
| `$billingAmount` | `?Money` | `null` | Amount charged each cycle |
| `$setupFee` | `?Money` | `null` | One-off fee on the first cycle |
| `$requestStatus` | `?string` | `null` | Raw gateway status for the request itself |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

Public method:
- `isSubscribable(): bool` — whether a new subscription can be created against this plan right now (Active only).

## AccountUpdaterBatch
`Hyprpay\Payments\Domain\Result\AccountUpdaterBatch` — an Account Updater batch: its progress and, once complete, what the networks changed.

| field | type | default | meaning |
|---|---|---|---|
| `$batchId` | `?string` | `null` | Batch id, used to poll status and fetch the report |
| `$status` | `?AccountUpdaterBatchStatus` | `null` | Normalised processing status |
| `$createdDate` | `?string` | `null` | When the batch was submitted (ISO 8601) |
| `$merchantReference` | `?string` | `null` | Reference the batch was submitted with |
| `$source` | `?string` | `null` | How it reached the gateway (e.g. `TOKEN_API`) |
| `$acceptedRecords` | `int` | `0` | Tokens accepted into the batch |
| `$rejectedRecords` | `int` | `0` | Tokens refused |
| `$updatedRecords` | `int` | `0` | Cards the networks reported a change for |
| `$networkResponses` | `int` | `0` | Responses received from the card associations |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

Public methods:
- `isComplete(): bool` — whether the report can be fetched.
- `hasUpdates(): bool` — whether the networks changed anything; only then is the report worth reading.

## ReportDefinition
`Hyprpay\Payments\Domain\Result\ReportDefinition` — a report the merchant may run: the catalogue entry behind `CreateReportRequest::$definitionName`. Which definitions exist, and which fields each offers, depends on merchant entitlements and subscription type, so this is **discovered from the gateway rather than fixed in the SDK** — freezing the set would reject names a merchant is legitimately entitled to.

| field | type | default | meaning |
|---|---|---|---|
| `$name` | `?string` | `null` | Definition name, passed as `reportDefinitionName` |
| `$id` | `?int` | `null` | Definition id, usable as a list filter |
| `$description` | `?string` | `null` | Human-readable description |
| `$type` | `?string` | `null` | Reporting category |
| `$subscriptionType` | `?ReportSubscriptionType` | `null` | Family the definition was resolved under |
| `$supportedFormats` | `list<ReportFormat>` | `[]` | Formats this report can be generated in |
| `$fields` | `list<ReportDefinitionField>` | `[]` | Selectable fields; empty on a listing, populated on a single lookup |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway response payload |

Public methods:
- `fieldNames(): list<string>` — every field on offer, ready to pass as `CreateReportRequest::$fields`.
- `requiredFieldNames(): list<string>` — the fields the definition always includes.
- `supports(ReportFormat $format): bool` — whether the report can be generated in that format.

## ReportDefinitionField
`Hyprpay\Payments\Domain\Result\ReportDefinitionField` — one selectable field on a report definition. The set is a property of the definition, not of reporting as a whole, which is why fields are discovered per definition rather than fixed in the SDK.

| field | type | default | meaning |
|---|---|---|---|
| `$name` | `?string` | `null` | Field name, as passed in `reportFields` |
| `$id` | `?string` | `null` | Gateway identifier for the field |
| `$description` | `?string` | `null` | What the column holds |
| `$isRequired` | `bool` | `false` | Whether the definition always includes it |
| `$isDefault` | `bool` | `false` | Whether it is included when no field list is given |
| `$filterType` | `?string` | `null` | How the field may be filtered on |
| `$supportedValues` | `?string` | `null` | Valid filter values, when declared |
| `$raw` | `array<string,mixed>` | `[]` | Raw gateway attribute payload |

No public methods.
