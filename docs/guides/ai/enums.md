# Domain Enums

Reference for the backed enums under `Hyprpay\Payments\Domain\Enum`. Each is a string-backed `enum`; the backing value is the stable machine key used in config, JSON, and factory lookups.

## PaymentStatus
`Hyprpay\Payments\Domain\Enum\PaymentStatus` — normalised, gateway-agnostic lifecycle status of a payment. Every driver maps its provider states onto these.

| case | backing value | `label()` | `isSuccessful()` |
|---|---|---|---|
| `Authorized` | `authorized` | Authorized | true |
| `Captured` | `captured` | Captured | true |
| `Pending` | `pending` | Pending | true |
| `Declined` | `declined` | Declined | false |
| `Voided` | `voided` | Voided | true |
| `Reversed` | `reversed` | Reversed | true |
| `Refunded` | `refunded` | Refunded | true |
| `Failed` | `failed` | Failed | false |

Methods:
- `label(): string` — human-readable display name (see table).
- `isSuccessful(): bool` — true for a non-error outcome. `Authorized`, `Captured`, `Pending`, `Refunded`, `Voided`, `Reversed` are successful; `Declined` and `Failed` are not.

## GatewayName
`Hyprpay\Payments\Domain\Enum\GatewayName` — canonical identifier for each payment gateway the SDK can drive. The backing value is the config/factory machine key; `PaymentGatewayFactory` matches on the case to construct the driver.

| case | backing value | `label()` |
|---|---|---|
| `CybersourceUnifiedCheckout` | `cybersource_uc` | CyberSource Unified Checkout |
| `Fawry` | `fawry` | Fawry |
| `Paymob` | `paymob` | Paymob |
| `Paylink` | `paylink` | PayLink |
| `Paytabs` | `paytabs` | PayTabs |
| `PayPal` | `paypal` | PayPal |
| `Mpgs` | `mpgs` | Mastercard Payment Gateway Services |
| `AuthorizeNet` | `authorize_net` | Authorize.Net |
| `Airwallex` | `airwallex` | Airwallex |

Methods:
- `label(): string` — human-readable display name for UIs and logs (see table).

## CredentialInitiator
`Hyprpay\Payments\Domain\Enum\CredentialInitiator` — who initiated a stored-credential (card-on-file) transaction, distinguishing customer-initiated (CIT) from merchant-initiated (MIT) transactions.

| case | backing value | `isMerchantInitiated()` |
|---|---|---|
| `Merchant` | `merchant` | true |
| `Customer` | `customer` | false |

Methods:
- `isMerchantInitiated(): bool` — true for merchant-initiated (MIT), false for customer-initiated (CIT).

## WalletType
`Hyprpay\Payments\Domain\Enum\WalletType` — the digital wallet whose device-encrypted token is charged via `chargeWallet`. The gateway driver maps each wallet to its provider-specific payment-solution id.

| case | backing value |
|---|---|
| `ApplePay` | `apple_pay` |
| `GooglePay` | `google_pay` |

## MandateCompletionType
`Hyprpay\Payments\Domain\Enum\MandateCompletionType` — orchestration mode requested from the CyberSource Unified Checkout v1 widget. Setting a `completeMandate` switches the widget from the manual transient-token flow to the orchestrated (autoProcessing) flow. The type selects the financial outcome.

| case | backing value | meaning |
|---|---|---|
| `Capture` | `CAPTURE` | Settles the payment (sale) |
| `Auth` | `AUTH` | Places an authorization hold only |

No methods.

## SubscriptionStatus
`Hyprpay\Payments\Domain\Enum\SubscriptionStatus` — normalised, gateway-agnostic lifecycle status of a recurring subscription. Distinct from `PaymentStatus`, which describes a single charge: a subscription outlives the payments it schedules. CyberSource's `CybersourceSubscriptionStatus` maps onto these one-for-one.

| case | backing value | `label()` | `isBilling()` | `isTerminal()` |
|---|---|---|---|---|
| `Pending` | `pending` | Pending | false | false |
| `Active` | `active` | Active | true | false |
| `Suspended` | `suspended` | Suspended | false | false |
| `Delinquent` | `delinquent` | Delinquent | false | false |
| `Cancelled` | `cancelled` | Cancelled | false | true |
| `Completed` | `completed` | Completed | false | true |
| `Failed` | `failed` | Failed | false | true |

Methods:
- `label(): string` — human-readable display name (see table).
- `isBilling(): bool` — true only for `Active`, the one state currently charging the cardholder.
- `isTerminal(): bool` — true for `Cancelled`, `Completed`, `Failed`; a `Suspended` or `Delinquent` subscription can still be revived with `activateSubscription`.

## BillingPeriodUnit
`Hyprpay\Payments\Domain\Enum\BillingPeriodUnit` — calendar unit a recurring billing period is measured in, paired with a length in `BillingPeriod`. The backing values are the single-character codes CyberSource expects in `planInformation.billingPeriod.unit`.

| case | backing value | `label()` |
|---|---|---|
| `Day` | `D` | Day |
| `Week` | `W` | Week |
| `Month` | `M` | Month |
| `Year` | `Y` | Year |

Methods:
- `label(): string` — human-readable display name for the unit.

## CybersourceCommerceIndicator
`Hyprpay\Payments\Domain\Enum\CybersourceCommerceIndicator` — commerce indicator declaring how a CyberSource Recurring Billing subscription was taken, sent as `processingInformation.commerceIndicator`. CyberSource ignores it when the request carries a `subscriptionInformation.originalTransactionId` or when updating a subscription. Not every processor accepts `Recurring` on the zero-dollar authorization run for a future-dated subscription — leave `CreateSubscriptionRequest::$commerceIndicator` null to let CyberSource pick.

| case | backing value |
|---|---|
| `Recurring` | `RECURRING` |
| `Internet` | `INTERNET` |
| `Moto` | `MOTO` |

No methods.

## ReportFormat
`Hyprpay\Payments\Domain\Enum\ReportFormat` — file format a gateway report is generated in. The backing values are the MIME types CyberSource uses both as the `reportMimeType` request field and as the `Accept` header on download — the download must ask for the format the report was created in.

| case | backing value | `extension()` | `label()` |
|---|---|---|---|
| `Csv` | `text/csv` | `csv` | CSV |
| `Xml` | `application/xml` | `xml` | XML |

## ReportFrequency
`Hyprpay\Payments\Domain\Enum\ReportFrequency` — how often a scheduled report is generated. `Adhoc` is not schedulable: it is the frequency a one-off report reports back as.

| case | backing value | `needsStartDay()` | `needsInterval()` |
|---|---|---|---|
| `Daily` | `DAILY` | false | false |
| `Weekly` | `WEEKLY` | true | false |
| `Monthly` | `MONTHLY` | true | false |
| `UserDefined` | `USER_DEFINED` | false | true |
| `Adhoc` | `ADHOC` | false | false |

Methods:
- `needsStartDay(): bool` — true for weekly (day 1-7, 1 is Sunday) and monthly (1-31) cadences.
- `needsInterval(): bool` — true for `UserDefined`, which also needs an ISO 8601 duration (e.g. `PT2H30M`).

## ReportStatus
`Hyprpay\Payments\Domain\Enum\ReportStatus` — generation status of a report. A report id existing does not mean a file exists behind it; check `isReady()` before downloading.

| case | backing value | `label()` | `isReady()` | `isInProgress()` | `isFailed()` |
|---|---|---|---|---|---|
| `Completed` | `COMPLETED` | Completed | true | false | false |
| `Pending` | `PENDING` | Pending | false | true | false |
| `Queued` | `QUEUED` | Queued | false | true | false |
| `Running` | `RUNNING` | Running | false | true | false |
| `Error` | `ERROR` | Error | false | false | true |
| `NoData` | `NO_DATA` | No data | false | false | false |

Methods:
- `label(): string` — human-readable display name.
- `isReady(): bool` — true only for `Completed`. `NoData` is a **successful** run that matched nothing: finished, but with no file to fetch.
- `isInProgress(): bool` — true while the report is still being produced; poll rather than treating the absent file as an error.
- `isFailed(): bool` — true only for `Error`.

## ReportSubscriptionType
`Hyprpay\Payments\Domain\Enum\ReportSubscriptionType` — which family of report definitions a definition name resolves against. CyberSource publishes the same reporting surface three ways and a name is only meaningful within one of them; asking under the wrong family is why a name that plainly exists comes back not found.

| case | backing value |
|---|---|
| `Custom` | `CUSTOM` |
| `Standard` | `STANDARD` |
| `Classic` | `CLASSIC` |

`Custom` is the modern, field-selectable set and the gateway's own default; `Standard` and `Classic` are the fixed legacy layouts.

## ReportDefinitionName
`Hyprpay\Payments\Domain\Enum\ReportDefinitionName` — the report types CyberSource documents as standard, passed as `reportDefinitionName` when creating a report or a report subscription.

| case | backing value | `label()` |
|---|---|---|
| `TransactionRequest` | `TransactionRequestClass` | Transaction Request Report |
| `PaymentBatchDetail` | `PaymentBatchDetailClass` | Payment Batch Detail Report |
| `ExceptionDetail` | `ExceptionDetailClass` | Transaction Exception Detail Report |
| `ProcessorSettlementDetail` | `ProcessorSettlementDetailClass` | Processor Settlement Detail Report |
| `ProcessorEventsDetail` | `ProcessorEventsDetailClass` | Processor Events Detail Report |
| `FundingDetail` | `FundingDetailClass` | Funding Detail Report |
| `AgingDetail` | `AgingDetailClass` | Aging Detail Report |
| `ChargebackAndRetrievalDetail` | `ChargebackAndRetrievalDetailClass` | Chargeback And Retrieval Detail Report |
| `DepositDetail` | `DepositDetailClass` | Deposit Detail Report |
| `FeeDetail` | `FeeDetailClass` | Fee Detail Report |
| `InvoiceSummary` | `InvoiceSummaryClass` | Invoice Summary Report |
| `PayerAuthDetail` | `PayerAuthDetailClass` | Payer Authentication Detail Report |
| `ConversionDetail` | `ConversionDetailClass` | Conversion Detail Report |
| `SubscriptionDetail` | `SubscriptionDetailClass` | Subscription Detail Report |
| `JpTransactionDetail` | `JPTransactionDetailClass` | JP Transaction Detail Report |
| `ServiceFeeDetail` | `ServiceFeeDetailClass` | Service Fee Detail Report |
| `GatewayTransactionRequest` | `GatewayTransactionRequestClass` | Gateway Transaction Request Report |
| `DecisionManagerEventDetail` | `DecisionManagerEventDetailClass` | Decision Manager Event Detail Report |
| `RecurringBillingDetail` | `RecurringBillingDetailClass` | Recurring Billing Details Report |

Methods:
- `label(): string` — the report's documented title (see table).
- `static resolve(self|string|null $name): ?self` — a name this enum does not model resolves to null, which is **not** an error: it is a custom or newly-published report.
- `static toValue(self|string $name): string` — render either form as the string CyberSource expects.
- `static allValues(): array<int,string>` — every documented name, e.g. for a report-type picker.

`CreateReportRequest::$definitionName` and `CreateReportSubscriptionRequest::$definitionName` accept `ReportDefinitionName|string`. The enum gives typo-safety for the documented set; the string escape hatch matters because a merchant's actual catalogue depends on entitlements, portfolio configuration, and the `ReportSubscriptionType` family, and may hold custom definitions. Confirm what an account can run with `listReportDefinitions()`.

> **Why there is no `ReportField` enum.** `reportFields` is typed `string[]` throughout the CyberSource spec, and the fields are a property of **each definition**, not of reporting as a whole — `getReportDefinition()` returns `attributes[]` where every entry carries its own `required`, `default`, `filterType`, and `supportedType`. A transaction report's columns are not a chargeback report's, so a single global field enum would be wrong by construction. Read the valid fields per definition from `ReportDefinition::fieldNames()` / `requiredFieldNames()`; see [results.md](results.md#reportdefinition).

## PaymentInstrumentState
`Hyprpay\Payments\Domain\Enum\PaymentInstrumentState` — the issuer's standing for a card behind a vaulted payment instrument, reported by the vault rather than by a charge. A closed account is why a scheduled rebill would decline permanently, and is worth acting on at rest.

| case | backing value | `isChargeable()` |
|---|---|---|
| `Active` | `ACTIVE` | true |
| `Closed` | `CLOSED` | false |

## PlanStatus
`Hyprpay\Payments\Domain\Enum\PlanStatus` — lifecycle status of a recurring billing plan (the template a subscription is created from). Only an active plan can back a new subscription; subscriptions already running are unaffected by deactivation.

| case | backing value | `label()` | `isSubscribable()` |
|---|---|---|---|
| `Draft` | `DRAFT` | Draft | false |
| `Active` | `ACTIVE` | Active | true |
| `Inactive` | `INACTIVE` | Inactive | false |

## AccountUpdaterBatchType
`Hyprpay\Payments\Domain\Enum\AccountUpdaterBatchType` — which network flow an Account Updater batch uses. Visa and Mastercard answer ad-hoc update requests; American Express requires cards to be *registered* first. A batch carries one kind or the other, never both.

| case | backing value |
|---|---|
| `OneOff` | `oneOff` |
| `AmexRegistration` | `amexRegistration` |

## AccountUpdaterBatchStatus
`Hyprpay\Payments\Domain\Enum\AccountUpdaterBatchStatus` — processing status of an Account Updater batch. The full cycle takes hours to days because it depends on the card associations answering, so poll rather than expecting immediate results.

| case | backing value | `label()` | `isInProgress()` | `isComplete()` | `isFailed()` |
|---|---|---|---|---|---|
| `Received` | `RECEIVED` | Received | true | false | false |
| `Validated` | `VALIDATED` | Validated | true | false | false |
| `Processing` | `PROCESSING` | Processing | true | false | false |
| `Completed` | `COMPLETED` | Completed | false | true | false |
| `Rejected` | `REJECTED` | Rejected | false | false | true |
| `Declined` | `DECLINED` | Declined | false | false | true |

## BinLookupStatus
`Hyprpay\Payments\Domain\Enum\BinLookupStatus` — outcome of a BIN lookup. `Multiple` and `NoMatch` both mean "unknown", not "declined" — neither is grounds for refusing a payment.

| case | backing value | `isResolved()` |
|---|---|---|
| `Completed` | `COMPLETED` | true |
| `Multiple` | `MULTIPLE` | false |
| `NoMatch` | `NO MATCH` | false |

## CardFundingSource
`Hyprpay\Payments\Domain\Enum\CardFundingSource` — how the account behind a card is funded. Drives surcharging rules, routing, and the expectation of partial approvals.

| case | backing value | `label()` | `canPartiallyApprove()` |
|---|---|---|---|
| `Credit` | `CREDIT` | Credit | false |
| `Debit` | `DEBIT` | Debit | false |
| `Prepaid` | `PREPAID` | Prepaid | **true** |
| `DeferredDebit` | `DEFERRED DEBIT` | Deferred debit | false |
| `Charge` | `CHARGE` | Charge | false |

## CardPlatform
`Hyprpay\Payments\Domain\Enum\CardPlatform` — who the card was issued to. A commercial card can qualify for Level 2/3 interchange when the transaction carries the extra line-item and tax data, which is what makes supplying that data worthwhile.

| case | backing value | `isCommercial()` |
|---|---|---|
| `Consumer` | `CONSUMER` | false |
| `Business` | `BUSINESS` | true |
| `Corporate` | `CORPORATE` | true |
| `Commercial` | `COMMERCIAL` | true |
| `Government` | `GOVERNMENT` | true |

## WebhookStatus
`Hyprpay\Payments\Domain\Enum\WebhookStatus` — delivery state of a subscription. The gateway can set this itself after repeated delivery failures.

| case | backing value | `isDelivering()` |
|---|---|---|
| `Active` | `ACTIVE` | true |
| `Inactive` | `INACTIVE` | false |

## WebhookSecurityType
`Hyprpay\Payments\Domain\Enum\WebhookSecurityType` — how the gateway authenticates to your endpoint. Only `Key` produces signed notifications `verifyWebhook()` can check; the oAuth variants use a bearer token instead, and `verifyWebhook()` would fail closed on them.

| case | backing value | `isSignatureVerifiable()` |
|---|---|---|
| `Key` | `key` | true |
| `OAuth` | `oAuth` | false |
| `OAuthJwt` | `oAuth_JWT` | false |

## WebhookRetryAlgorithm
`Hyprpay\Payments\Domain\Enum\WebhookRetryAlgorithm` — how the delay between delivery retries grows. With `firstRetry: 10` and `interval: 30`, arithmetic gives 10/40/70 minutes; geometric gives 10/300/9,000.

| case | backing value |
|---|---|
| `Arithmetic` | `ARITHMETIC` |
| `Geometric` | `GEOMETRIC` |

## CybersourceCardNetwork — resolution helpers

The card brand reaches the SDK in three shapes depending on origin: a numeric code from BIN lookup and the vault, a lowercase name from a verified orchestrated result, and an uppercase name from BIN lookup's brand field. These collapse them all:

- `static fromCyberSourceCode(?string $code): ?self` — `001` → `Visa`. Null for a network not modelled.
- `static fromBrandName(?string $name): ?self` — tolerates spelling differences (`AMERICAN EXPRESS` → `Amex`, `CHINA UNION PAY` → `Cup`, `diners` → `DinersClub`).
- `static resolve(?string $value): ?self` — either representation.

`BinLookupResult::network()`, `PaymentInstrument::network()`, and `OrchestratedPaymentResult::network()` all return the same case, so branching on the network is one `match`.
