# Value Objects

Reference for the value objects under `Hyprpay\Payments\Domain\ValueObject`. Most are a `final readonly class` with an all-`public` promoted-property constructor; `WalletToken` is a marker interface with two implementations. `Money` never loses precision (integer minor units); the contact/device objects serialise only populated fields.

## Money
`Hyprpay\Payments\Domain\ValueObject\Money` — immutable amount in minor units plus currency and decimal scale. Used everywhere amounts appear.

Constructor:

| param | type | default | meaning |
|---|---|---|---|
| `$minorAmount` | `int` | — | Amount in minor units/subunits (e.g. 10000 for 100.00 at scale 2) |
| `$currency` | `string` | — | ISO 4217 currency code (case preserved as given) |
| `$scale` | `int` | `2` | Decimal places the currency uses (2 for most, 0 for zero-decimal) |

Throws `InvalidArgumentException` when `$scale < 0`.

Methods:
- `static minor(int $minorAmount, string $currency, int $scale = 2): self` — build from minor units; **uppercases** the currency code.
- `static fromDecimalString(string $amount, string $currency): self` — build from an exact decimal string (e.g. `"100.00"`). Scale is inferred from the fractional digits (`"9.60"`→2, `"480"`→0); currency uppercased; no rounding. Throws `InvalidArgumentException` on a non-decimal string (must match `^-?\d+(\.\d+)?$`).
- `toDecimalString(): string` — render as an exact decimal string without rounding (scale 0 returns the integer with no decimal point; preserves sign).

## Customer
`Hyprpay\Payments\Domain\ValueObject\Customer` — identifies the customer behind a payment; embedded in request DTOs. Every field optional.

| field | type | default | meaning |
|---|---|---|---|
| `$reference` | `?string` | `null` | Merchant-side customer identifier |
| `$email` | `?string` | `null` | Customer's email address |
| `$firstName` | `?string` | `null` | Customer's given name |
| `$lastName` | `?string` | `null` | Customer's family name |

No public methods.

## BillingAddress
`Hyprpay\Payments\Domain\ValueObject\BillingAddress` — payer's billing contact and postal details; every field optional. Passed into request DTOs so the gateway can populate its `billTo` block.

| field | type | default | meaning |
|---|---|---|---|
| `$firstName` | `?string` | `null` | Payer's given name |
| `$lastName` | `?string` | `null` | Payer's family name |
| `$email` | `?string` | `null` | Payer's email address |
| `$phoneNumber` | `?string` | `null` | Payer's contact phone number |
| `$address1` | `?string` | `null` | First line of the street address |
| `$address2` | `?string` | `null` | Second line (suite, unit, etc.) |
| `$locality` | `?string` | `null` | City or town |
| `$administrativeArea` | `?string` | `null` | State, province, or region |
| `$postalCode` | `?string` | `null` | ZIP or postal code |
| `$country` | `?string` | `null` | Two-letter ISO country code |

Methods:
- `toArray(): array<string,string>` — build the CyberSource `billTo` shape, including only fields that are present (`filled()`).
- `isEmpty(): bool` — true when `toArray()` yields an empty array (nothing to send).

## BrowserDeviceData
`Hyprpay\Payments\Domain\ValueObject\BrowserDeviceData` — EMV 3-D Secure browser device fields collected on the checkout page; carried on `ValidatePayerAuthRequest`. Richer data improves the chance of frictionless (no-challenge) authentication. Every field optional; only supplied ones are sent.

| field | type | default | meaning |
|---|---|---|---|
| `$ipAddress` | `?string` | `null` | Client IP address the request originated from |
| `$userAgent` | `?string` | `null` | Browser's User-Agent header value |
| `$acceptHeaders` | `?string` | `null` | Browser's Accept header value |
| `$colorDepth` | `?int` | `null` | Screen colour depth in bits per pixel |
| `$javaEnabled` | `?bool` | `null` | Whether the browser can run Java |
| `$javaScriptEnabled` | `?bool` | `null` | Whether the browser can run JavaScript |
| `$language` | `?string` | `null` | Browser language per IETF BCP 47 (e.g. en-US) |
| `$screenHeight` | `?int` | `null` | Total screen height in pixels |
| `$screenWidth` | `?int` | `null` | Total screen width in pixels |
| `$timeZone` | `?int` | `null` | Difference in minutes between UTC and the browser's local time |
| `$challengeWindowSize` | `?string` | `null` | Preferred 3DS challenge window size (e.g. FULL_SCREEN) |

Methods:
- `isEmpty(): bool` — true when every field is null (nothing to send).

## Installment
`Hyprpay\Payments\Domain\ValueObject\Installment` — an issuer-funded installment plan attached to a `ChargeRequest` to split the charge into installments the issuer funds (common across MENA, LATAM, and Turkey), rather than the merchant splitting it into separate stored-credential charges. Maps to `processingInformation.installment`; only supplied fields are sent.

| field | type | default | meaning |
|---|---|---|---|
| `$totalCount` | `int` | — | Total number of installments the plan is split into |
| `$sequence` | `?int` | `null` | This installment's number within the plan (1-based), for a subsequent part |
| `$planType` | `?string` | `null` | Processor-specific installment plan type |
| `$gracePeriodDuration` | `?int` | `null` | Grace period before the first installment, in the processor's unit (usually months) |

Methods:
- `toArray(): array<string, int|string>` — the `processingInformation.installment` fields, omitting any not supplied.

## GatewayCredentials
`Hyprpay\Payments\Domain\ValueObject\GatewayCredentials` — per-gateway credentials and settings a driver needs (API host, merchant/key ids, HMAC shared secret, optional webhook secret, localisation defaults). Produced by the credential resolver.

Constructor:

| param | type | default | meaning |
|---|---|---|---|
| `$host` | `string` | — | Gateway API hostname requests are sent to |
| `$merchantId` | `string` | — | Merchant identifier registered with the gateway |
| `$apiKeyId` | `string` | — | Public API key identifier used as the HTTP-Signature key id |
| `$sharedSecret` | `string` | — | Base64 shared secret used to compute the HMAC request signature |
| `$testMode` | `bool` | `true` | Whether credentials target the sandbox/test environment |
| `$webhookSecret` | `?string` | `null` | Secret to verify inbound webhook signatures, or null when not configured |
| `$country` | `string` | `'EG'` | ISO country code applied to requests |
| `$locale` | `string` | `'en_US'` | Locale used for the hosted checkout/UI |
| `$currency` | `string` | `'EGP'` | Default ISO currency code for transactions |
| `$extra` | `array<string,mixed>` | `[]` | Gateway-specific config that does not fit the standard fields (e.g. Paymob per-method integration and iframe ids) |

Methods:
- `static fromConfig(array $config): self` — build from a raw gateway config array. Resolves `host` from an explicit `host`, else by `test_mode` from `test_host`/`live_host` (falling back to `apitest.cybersource.com` / `api.cybersource.com`); coerces remaining fields to defaults.
- `isComplete(): bool` — true when the `sharedSecret` is non-empty (the one field every gateway needs). Per-gateway fields (merchant id, api key id, integration ids) are validated by individual drivers at call time.
- `extra(string $key, mixed $default = null): mixed` — read a value from the `extra` bag by dot path (`data_get`).
- `hasWebhookSecret(): bool` — true when a webhook verification secret is configured (`filled()`).
- `toSigningArray(): array{host:string, merchant_id:string, api_key_id:string, shared_secret:string}` — export the fields required by the HMAC request-signing trait.

## WalletToken
`Hyprpay\Payments\Domain\ValueObject\WalletToken` — marker interface for a digital-wallet payment token supplied to `chargeWallet`, in one of two shapes. The SDK never decrypts a token itself; the gateway driver maps whichever shape it receives.

### DecryptedWalletToken
`Hyprpay\Payments\Domain\ValueObject\DecryptedWalletToken` — the canonical shape: a wallet token the merchant already decrypted into network-token fields (CyberSource: `paymentInformation.tokenizedCard`, `transactionType` 1).

| param | type | default | meaning |
|---|---|---|---|
| `$number` | `string` | — | Device primary account number (DPAN) from the decrypted token |
| `$cryptogram` | `string` | — | Online payment cryptogram from the decrypted token |
| `$expiryMonth` | `string` | — | Two-digit expiry month (`MM`) |
| `$expiryYear` | `string` | — | Four-digit expiry year (`YYYY`) preferred |
| `$eci` | `?string` | `null` | Electronic Commerce Indicator from the decrypted token, when present |
| `$cardType` | `?string` | `null` | Gateway card-network code (e.g. CyberSource `001` Visa, `002` Mastercard); omitted when null |

### EncryptedWalletToken
`Hyprpay\Payments\Domain\ValueObject\EncryptedWalletToken` — the opt-in alternative: a wallet token forwarded to the gateway still encrypted, for the gateway to decrypt (CyberSource: `paymentInformation.fluidData`).

| param | type | default | meaning |
|---|---|---|---|
| `$value` | `string` | — | The wallet's device-encrypted payment token as delivered client-side (Apple Pay: `paymentData` serialized to JSON) |
