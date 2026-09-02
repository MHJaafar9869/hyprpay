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
| `$allowedPaymentTypes` | `array<int, CybersourcePaymentType>` | `[CybersourcePaymentType::PanEntry]` | UC: payment types the widget may offer (`PanEntry`, `GooglePay`, `ApplePay`, `ClickToPay`, `ECheck`, `Paze`) |
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
| `$installment` | `?Installment` | `null` | Issuer-funded installment plan to split the charge across (maps to `processingInformation.installment`) |

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
`Hyprpay\Payments\Domain\Command\WalletChargeRequest` — charges a native digital-wallet token (Apple Pay / Google Pay). The token is supplied as a `WalletToken` — already-decrypted network-token fields (canonical) or an encrypted blob the gateway decrypts — and the SDK never decrypts it or handles the cleartext PAN.

| param | type | default | meaning |
|---|---|---|---|
| `$token` | `WalletToken` | — | The wallet token: `DecryptedWalletToken` (DPAN, cryptogram, expiry, optional ECI/card type the merchant decrypted — canonical) or `EncryptedWalletToken` (raw device-encrypted token the gateway decrypts) |
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

## Recurring subscriptions

### CreateSubscriptionRequest
`Hyprpay\Payments\Domain\Command\CreateSubscriptionRequest` — opens a subscription the gateway bills on its own schedule (CyberSource Recurring Billing, `POST /rbs/v1/subscriptions`). Carries no card data: it references an already-vaulted customer token, so vault the card first. Nothing is charged when the subscription is created — the first charge falls on `$startDate`. The cadence comes from `$planId`, from the inline `$billingPeriod`/`$billingCycles`, or from both with the inline values overriding the plan.

| param | type | default | meaning |
|---|---|---|---|
| `$name` | `string` | — | Human-readable subscription name shown in the gateway's back office |
| `$customerId` | `string` | — | Vault customer identifier whose default instrument is billed each cycle |
| `$startDate` | `string` | — | Date of the first charge, UTC — `YYYY-MM-DD` (expanded to midnight UTC) or a full `YYYY-MM-DDThh:mm:ssZ` |
| `$planId` | `?string` | `null` | Identifier of an existing billing plan supplying cadence and amount; omit to define them inline |
| `$billingPeriod` | `?BillingPeriod` | `null` | Inline billing cadence (e.g. every month), overriding the plan's period |
| `$billingCycles` | `?int` | `null` | Total cycles to bill before completing; null bills until cancelled |
| `$billingAmount` | `?Money` | `null` | Amount charged each cycle, overriding the plan's amount |
| `$setupFee` | `?Money` | `null` | One-off fee charged on the first cycle, in the same currency as the billing amount |
| `$code` | `?string` | `null` | Merchant-assigned subscription code; the gateway generates one when omitted |
| `$orderReference` | `?string` | `null` | Merchant order/reference number for reconciliation |
| `$originalTransactionId` | `?string` | `null` | Network transaction id of the initial cardholder-initiated charge, threading this series to its CIT |
| `$originalAuthorizedAmount` | `?Money` | `null` | Amount of that initial charge; required by Diners and Discover |
| `$commerceIndicator` | `?CybersourceCommerceIndicator` | `null` | How the recurring agreement was taken; ignored when an original transaction id is supplied |
| `$initiator` | `?CredentialInitiator` | `null` | Who initiates the recurring charges; ignored when an original transaction id is supplied |
| `$idempotencyKey` | `?string` | `null` | Idempotency key so a retried create does not open a second subscription; defaults to `$orderReference` |

### UpdateSubscriptionRequest
`Hyprpay\Payments\Domain\Command\UpdateSubscriptionRequest` — amends an existing subscription in place (`PATCH /rbs/v1/subscriptions/{id}`). A partial update: only the fields supplied are sent and everything left null keeps its current value. Narrower than a create by CyberSource's own schema — there is no `BillingPeriod` and no currency, because the cadence and the billing currency are fixed once the subscription exists. The vault customer cannot be re-pointed either; to move a subscription onto a different card, update the payment instrument behind the existing customer token.

| param | type | default | meaning |
|---|---|---|---|
| `$subscriptionId` | `string` | — | Identifier of the subscription to amend |
| `$name` | `?string` | `null` | New human-readable subscription name |
| `$planId` | `?string` | `null` | Move the subscription onto a different billing plan |
| `$code` | `?string` | `null` | New merchant-assigned subscription code |
| `$startDate` | `?string` | `null` | Reschedule the first charge, UTC; only meaningful while the subscription has not started |
| `$billingCycles` | `?int` | `null` | New total number of cycles to bill before completing |
| `$billingAmount` | `?Money` | `null` | New per-cycle amount; **its currency is ignored** |
| `$setupFee` | `?Money` | `null` | New one-off setup fee; **its currency is ignored** |
| `$orderReference` | `?string` | `null` | Merchant order/reference number for reconciliation |
| `$idempotencyKey` | `?string` | `null` | Idempotency key so a retried update is not applied twice; defaults to `$orderReference` |

The response can carry `requestStatus` `PENDING_REVIEW` — accepted but held for review rather than applied. The SDK reports that as `success: true` (as it does every pending state); read `$requestStatus` to tell it apart from an applied `COMPLETED`.

### ListSubscriptionsRequest
`Hyprpay\Payments\Domain\Command\ListSubscriptionsRequest` — one page of subscriptions (`GET /rbs/v1/subscriptions`, CyberSource's `getAllSubscriptions`). Every filter is optional and they combine; passing none walks the whole book. Returns a `SubscriptionPage`, not a bare list — CyberSource defaults to 20 records and caps a page at 100, so page through with `SubscriptionPage::hasMore()` and `nextPage()`.

| param | type | default | meaning |
|---|---|---|---|
| `$status` | `?SubscriptionStatus` | `null` | Return only subscriptions in this lifecycle state (mapped back to CyberSource's spelling) |
| `$code` | `?string` | `null` | Return only the subscription carrying this subscription code |
| `$customerId` | `?string` | `null` | Return only subscriptions billing this vault customer |
| `$orderReference` | `?string` | `null` | Return only subscriptions carrying this merchant reference (`clientReferenceInformationCode`) |
| `$limit` | `int` | `20` | Page size; CyberSource caps it at 100 |
| `$offset` | `int` | `0` | Records to skip, for paging |

Public method:
- `nextPage(): self` — the request for the following page, keeping every filter and the page size.

The remaining lifecycle operations take no DTO — `getSubscription(string $id)`, `cancelSubscription(string $id)`, `suspendSubscription(string $id)`, and `activateSubscription(string $id, bool $processMissedPayments = true)` — and each returns a `SubscriptionResult`.

## Reporting

### CreateReportRequest
`Hyprpay\Payments\Domain\Command\CreateReportRequest` — queues a one-off (ad-hoc) report over a fixed window (`POST /reporting/v3/reports`). Generation is asynchronous and the gateway answers with an empty 201, so the operation returns `bool`: find the queued report with `listReports` and download it once ready.

| param | type | default | meaning |
|---|---|---|---|
| `$name` | `string` | — | Merchant-assigned name for this run; the handle it is later downloaded by |
| `$definitionName` | `ReportDefinitionName\|string` | — | Report definition to run — an enum case for the 19 documented reports, or a raw name for a custom one |
| `$startTime` | `string` | — | Start of the window, UTC ISO 8601; a bare `YYYY-MM-DD` becomes midnight UTC |
| `$endTime` | `string` | — | End of the window, same format |
| `$format` | `ReportFormat` | `Csv` | File format to generate in |
| `$fields` | `array<int,string>` | `[]` | Columns to include; empty uses the definition's defaults |
| `$timezone` | `?string` | `null` | Timezone the window is interpreted in |
| `$filters` | `array<string,array<int,string>>` | `[]` | Report filters as field => allowed values |
| `$groupName` | `?string` | `null` | Report group to file the report under |
| `$organizationId` | `?string` | `null` | Organization; defaults to the credentials' organization |

### ListReportsRequest
`Hyprpay\Payments\Domain\Command\ListReportsRequest` — finds the reports available over a window (`GET /reporting/v3/reports`). The window is required. `$timeQueryType` decides which timestamp it filters on — `executedTime` (when the report ran) or `reportTimeFrame` (the period it covers); they differ for any report generated after the data it describes.

| param | type | default | meaning |
|---|---|---|---|
| `$startTime` | `string` | — | Start of the search window, UTC ISO 8601 |
| `$endTime` | `string` | — | End of the search window |
| `$timeQueryType` | `string` | `executedTime` | `TIME_EXECUTED` or `TIME_REPORT_FRAME` |
| `$status` | `?ReportStatus` | `null` | Only reports in this generation state |
| `$frequency` | `?ReportFrequency` | `null` | Only reports produced at this cadence |
| `$format` | `?ReportFormat` | `null` | Only reports in this file format |
| `$name` | `?string` | `null` | Only reports carrying this name |
| `$definitionId` | `?int` | `null` | Only reports from this report definition |
| `$organizationId` | `?string` | `null` | Organization to search |

Constants: `TIME_EXECUTED` (`executedTime`), `TIME_REPORT_FRAME` (`reportTimeFrame`).

### DownloadReportRequest
`Hyprpay\Payments\Domain\Command\DownloadReportRequest` — fetches a generated report file (`GET /reporting/v3/report-downloads`). Keyed by name and date, **not** report id. The date is the *end* of the period covered, in the report's timezone — a report running midnight-to-midnight on the 9th downloads under the 10th; getting this wrong is the usual cause of a 404 on a report that exists. Prefer `Report::downloadRequest()`, which derives it.

| param | type | default | meaning |
|---|---|---|---|
| `$name` | `string` | — | Name of the report to download |
| `$reportDate` | `string` | — | `YYYY-MM-DD` end date of the period covered, in the report's timezone |
| `$format` | `ReportFormat` | `Csv` | Format the report was generated in; sent as the `Accept` header |
| `$organizationId` | `?string` | `null` | Organization the report belongs to |

### CreateReportSubscriptionRequest
`Hyprpay\Payments\Domain\Command\CreateReportSubscriptionRequest` — schedules a recurring report (`PUT /reporting/v3/report-subscriptions`). The endpoint is keyed by report name, so creating one under an existing name **replaces** that schedule — the same call creates and updates.

| param | type | default | meaning |
|---|---|---|---|
| `$name` | `string` | — | Unique subscription name; re-using one replaces that subscription |
| `$definitionName` | `ReportDefinitionName\|string` | — | Report definition to run — an enum case, or a raw name for a custom definition |
| `$fields` | `array<int,string>` | — | Columns to include in each generated report |
| `$startTime` | `string` | — | Time of day each run starts, as `hhmm` (e.g. `0200`) — not a date |
| `$frequency` | `ReportFrequency` | `Daily` | How often the report is generated |
| `$format` | `ReportFormat` | `Csv` | File format each run is generated in |
| `$timezone` | `?string` | `null` | Timezone the schedule runs in |
| `$startDay` | `?int` | `null` | 1-7 weekly (1 is Sunday) or 1-31 monthly; ignored for other cadences |
| `$interval` | `?string` | `null` | ISO 8601 duration for a `UserDefined` cadence (e.g. `PT2H30M`) |
| `$filters` | `array<string,array<int,string>>` | `[]` | Report filters as field => allowed values |
| `$groupName` | `?string` | `null` | Report group to file the subscription under |
| `$organizationId` | `?string` | `null` | Organization the subscription belongs to |

`startDay` and `interval` are emitted only for the cadences that use them, so a daily subscription does not carry fields the gateway would reject.

The read/delete operations take no DTO — `listReportSubscriptions(?string $organizationId = null)`, `getReportSubscription(string $reportName, ?string $organizationId = null)`, and `deleteReportSubscription(string $reportName, ?string $organizationId = null)`.

## Bank account validation

### ValidateBankAccountRequest
`Hyprpay\Payments\Domain\Command\ValidateBankAccountRequest` — Visa Bank Account Validation Service (`POST /bavs/v1/account-validations`). Checks a routing/account pair is a real, open account **before** an ACH debit, satisfying Nacha's account-validation mandate for WEB debits. It authorises nothing and moves no money. Supply either the raw bank details or a vault token — with a token the bank fields become optional and the raw numbers never leave the vault.

| param | type | default | meaning |
|---|---|---|---|
| `$routingNumber` | `?string` | `null` | Bank routing (transit) number, digits only |
| `$accountNumber` | `?string` | `null` | Bank account number, digits only |
| `$customerId` | `?string` | `null` | Vault customer token holding the account, used instead of raw details |
| `$paymentInstrumentId` | `?string` | `null` | Vault payment-instrument token for the account |
| `$instrumentIdentifierId` | `?string` | `null` | Vault instrument-identifier token for the account |
| `$orderReference` | `?string` | `null` | Merchant reference for reconciling the validation |
| `$validationLevel` | `int` | `1` | Depth of validation; `LEVEL_ROUTING_AND_ACCOUNT` checks both numbers |

A half-supplied bank pair (routing without account, or vice versa) is dropped rather than sent as a block the service would reject. Routing and account numbers are sensitive: the operation sits outside `PaymentGatewayInterface`, so the logging decorator never sees them.

## Vault lifecycle

### UpdatePaymentInstrumentRequest
`Hyprpay\Payments\Domain\Command\UpdatePaymentInstrumentRequest` — amends a vaulted payment instrument in place (`PATCH /tms/v2/customers/{id}/payment-instruments/{id}`). A partial update; only the fields supplied are sent. The card **number** is never updatable — it belongs to the instrument identifier behind the instrument — so a genuinely different card is vaulted afresh.

| param | type | default | meaning |
|---|---|---|---|
| `$customerId` | `string` | — | Vault customer the instrument is stored under |
| `$paymentInstrumentId` | `string` | — | Vault payment-instrument identifier to amend |
| `$expirationMonth` | `?string` | `null` | New two-digit expiry month (`MM`) |
| `$expirationYear` | `?string` | `null` | New four-digit expiry year (`YYYY`) |
| `$cardType` | `?string` | `null` | New gateway card-network code (e.g. `001` Visa) |
| `$billTo` | `?BillingAddress` | `null` | Replacement billing address |
| `$makeDefault` | `?bool` | `null` | Makes this the customer's default instrument |

The common use is re-dating a reissued card: it keeps every subscription and stored-credential charge already pointing at the instrument working with no re-collection. `makeDefault` is also the prerequisite for deleting whichever instrument is currently the default.

The read/delete operations take no DTO — `getPaymentInstrument(string $customerId, string $paymentInstrumentId)`, `listPaymentInstruments(string $customerId, int $limit = 20, int $offset = 0)`, `deletePaymentInstrument(string $customerId, string $paymentInstrumentId)`, `getCustomer(string $customerId)`, `deleteCustomer(string $customerId)`, and `deleteInstrumentIdentifier(string $instrumentIdentifierId)`.

## Plans

### CreatePlanRequest
`Hyprpay\Payments\Domain\Command\CreatePlanRequest` — creates the reusable template a subscription is built from (`POST /rbs/v1/plans`), which `CreateSubscriptionRequest::$planId` then references.

| param | type | default | meaning |
|---|---|---|---|
| `$name` | `string` | — | Plan name shown in the back office |
| `$billingPeriod` | `BillingPeriod` | — | How often a subscription on this plan charges |
| `$billingAmount` | `?Money` | `null` | Amount charged each cycle |
| `$setupFee` | `?Money` | `null` | One-off fee on the first cycle, same currency |
| `$billingCycles` | `?int` | `null` | Cycles before completing; null bills until cancelled |
| `$description` | `?string` | `null` | Human-readable description |
| `$code` | `?string` | `null` | Merchant-assigned plan code; the gateway generates one when omitted |
| `$status` | `?PlanStatus` | `null` | `Draft` to stage it, `Active` to publish; the gateway defaults to Active |

### UpdatePlanRequest
`Hyprpay\Payments\Domain\Command\UpdatePlanRequest` — amends a plan (`PATCH /rbs/v1/plans/{id}`). A partial update. **Unlike a subscription, a plan's billing period can be changed here** — a plan is a template with nothing billing against it. The change governs subscriptions created afterwards; it does not retroactively re-price those already running.

| param | type | default | meaning |
|---|---|---|---|
| `$planId` | `string` | — | Plan to amend |
| `$name` | `?string` | `null` | New plan name |
| `$description` | `?string` | `null` | New description |
| `$billingPeriod` | `?BillingPeriod` | `null` | New cadence for future subscriptions |
| `$billingCycles` | `?int` | `null` | New total cycle count |
| `$billingAmount` | `?Money` | `null` | New per-cycle amount |
| `$setupFee` | `?Money` | `null` | New one-off setup fee |

The remaining plan operations take no DTO — `getPlan(string)`, `listPlans()`, `activatePlan(string)`, `deactivatePlan(string)`, `deletePlan(string)`, `generatePlanCode()`, and `listSubscriptionPayments(string $subscriptionId)`.

## Account Updater

### CreateAccountUpdaterBatchRequest
`Hyprpay\Payments\Domain\Command\CreateAccountUpdaterBatchRequest` — submits vaulted cards for network refresh (`POST /accountupdater/v1/batches`). Only token ids are sent; no card number leaves the vault. Submission is asynchronous — the call returns a batch id to poll, not results.

| param | type | default | meaning |
|---|---|---|---|
| `$tokens` | `list<AccountUpdaterToken>` | — | Vault tokens to refresh |
| `$type` | `AccountUpdaterBatchType` | `OneOff` | Network flow; Amex cards must use `AmexRegistration` |
| `$merchantReference` | `?string` | `null` | Reference echoed back on the batch |

Named constructor:
- `static forTokenIds(array $tokenIds, AccountUpdaterBatchType $type = OneOff, ?string $merchantReference = null): self` — the common case, a batch of plain token ids with no stored expiry to send alongside.

The poll/report operations take no DTO — `getAccountUpdaterBatchStatus(string $batchId)`, `getAccountUpdaterBatchReport(string $batchId)`, and `listAccountUpdaterBatches(int $limit = 20, int $offset = 0, ?string $fromDate = null, ?string $toDate = null)` (dates in ISO 8601 basic format, `yyyyMMddTHHmmssZ`).
