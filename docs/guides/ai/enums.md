# Domain Enums

Reference for the four backed enums under `Hyprpay\Payments\Domain\Enum`. Each is a string-backed `enum`; the backing value is the stable machine key used in config, JSON, and factory lookups.

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
