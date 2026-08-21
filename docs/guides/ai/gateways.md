# hyprpay/payments — Gateway Reference (AI/LLM)

Terse, code-forward reference to all nine payment gateway drivers in `hyprpay/payments`. Audience: AI coding assistants. Every class under `src/Infrastructure/Gateway/` is documented against the real source — no invented behavior.

## Architecture in one screen

- **Port:** `Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface` — the 17-method contract every driver implements: `name`, `credentials`, `createCheckoutSession`, `requestDccRate`, `charge`, `capture`, `refund`, `void`, `reverseAuthorization`, `enrollPayerAuth`, `validatePayerAuth`, `vaultInstrument`, `chargeStoredCredential`, `chargeWallet`, `getTransaction`, `searchTransaction`, `verifyWebhook`.
- **Base:** `Hyprpay\Payments\Domain\AbstractPaymentGateway` — constructed with `GatewayCredentials`; provides a default for every operation that throws `UnsupportedOperationException::forOperation($this->name(), '<op>')`. Concrete drivers **override only what they support**; everything else is "inherited-as-unsupported" and throws.
- **Resolution:** `Hyprpay\Payments\Domain\Enum\GatewayName` (string-backed) identifies each gateway. `Hyprpay\Payments\Application\PaymentGatewayFactory::make(GatewayName, ?GatewayCredentials)` constructs the driver via a `match`, wiring the shared `HttpClient` and resolved credentials, then optionally wraps it in `EventDispatchingGateway` (when an `EventDispatcher` is supplied) and `LoggingGateway` (when a `LoggerInterface` is supplied). `makeByName(string)` maps the enum backing value via `GatewayName::tryFrom`.
- **Normalized status:** every driver folds provider states onto `Hyprpay\Payments\Domain\Enum\PaymentStatus`: `Authorized`, `Captured`, `Pending`, `Declined`, `Voided`, `Reversed`, `Refunded`, `Failed`. `isSuccessful()` treats all but `Declined`/`Failed` as successful.
- **Typed options:** `Hyprpay\Payments\Domain\Command\CheckoutOptions` — per-gateway typed DTO carried on `CheckoutSessionRequest`; `toArray()` renders the gateway's raw option-key array (nulls dropped). Six gateways ship one; Cybersource and AuthorizeNet do not.

### GatewayName cases

| Case | Backing value | Label |
| --- | --- | --- |
| `CybersourceUnifiedCheckout` | `cybersource_uc` | CyberSource Unified Checkout |
| `Fawry` | `fawry` | Fawry |
| `Paymob` | `paymob` | Paymob |
| `Paylink` | `paylink` | PayLink |
| `Paytabs` | `paytabs` | PayTabs |
| `PayPal` | `paypal` | PayPal |
| `Mpgs` | `mpgs` | Mastercard Payment Gateway Services |
| `AuthorizeNet` | `authorize_net` | Authorize.Net |
| `Airwallex` | `airwallex` | Airwallex |

### Operation-support matrix

Legend: ● implemented · — inherited-as-unsupported (throws `UnsupportedOperationException`).

| Operation | Cybersource | Fawry | Paymob | Paylink | Paytabs | PayPal | Mpgs | AuthorizeNet | Airwallex |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| createCheckoutSession | ● | ● | ● | ● | ● | ● | ● | — | ● |
| requestDccRate | ● | — | — | — | — | — | — | — | — |
| charge | ● | — | — | — | ● | ● | ● | ● | ● |
| capture | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| refund | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| void | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| reverseAuthorization | ● | — | — | ● | ● | — | ● | — | ● |
| enrollPayerAuth | ● | — | — | — | — | — | ● | — | — |
| validatePayerAuth | ● | — | — | — | — | — | ● | — | — |
| vaultInstrument | ● | — | — | ● | — | ● | ● | ● | ● |
| chargeStoredCredential | ● | — | — | ● | ● | ● | ● | ● | ● |
| chargeWallet | ● | — | — | — | — | — | — | — | — |
| getTransaction | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| searchTransaction | ● | — | ● | — | — | — | ● | — | ● |
| verifyWebhook | ● | ● | ● | ● | ● | ● | ● | ● | ● |

Non-interface extras: Paylink and Paytabs each expose `deleteToken(string): bool`; Cybersource exposes `confirmOrchestratedPayment(ConfirmOrchestratedPaymentRequest): OrchestratedPaymentResult`.

---

## CyberSource Unified Checkout

- **Driver:** `CybersourceUnifiedCheckoutGateway` extends `AbstractPaymentGateway`. Resolved by `GatewayName::CybersourceUnifiedCheckout` (`cybersource_uc`). Uses traits `ParsesTransientToken`, `VerifiesCybersourceWebhook`, `VerifiesResultJwt`. The most complete driver — implements every interface operation.
- **CheckoutOptions:** none. Configured directly from `CheckoutSessionRequest`.
- **Client — `CybersourceClient`:** host from `GatewayCredentials::host`, URL `https://{host}{path}`. Auth is **CyberSource HTTP Signature (HMAC-SHA256)**. Signing string headers in strict order: `(request-target)`, `host`, `digest` (POST/PUT/PATCH only), `v-c-date`, `v-c-merchant-id`; digest = `SHA-256=base64(sha256(payload))`; shared secret base64-decoded before use as the HMAC key; `Signature: keyid="{apiKeyId}", algorithm="HmacSHA256", headers="...", signature="{base64}"`. Extra headers: `v-c-merchant-id`, `v-c-date` (RFC 7231), optional unsigned `v-c-idempotency-key`. `Accept: application/hal+json` on GET, `application/json` on POST. Non-2xx → `GatewayRequestException`.

**Operations:** `createCheckoutSession`, `requestDccRate`, `charge`, `capture`, `refund`, `void`, `reverseAuthorization`, `enrollPayerAuth`, `validatePayerAuth`, `vaultInstrument`, `chargeStoredCredential`, `chargeWallet` (native Apple Pay / Google Pay token forwarded as `fluidData` for CyberSource to decrypt; `paymentSolution` `001`/`012`), `getTransaction`, `searchTransaction`, `verifyWebhook`, plus `confirmOrchestratedPayment` (UC v1 autoProcessing; offline RS256 JWT verification, no server round-trip).

**Concerns (`Concerns/`):**
- `ParsesTransientToken` — decodes the *unsigned* UC transient-token / capture-context JWT (base64url payload only, not cryptographically verified); extracts `jti`, `content`/`data` claims, and Apple Pay/Google Pay wallet markers.
- `SignsCybersourceRequests` — builds the HMAC-SHA256 HTTP Signature headers per the ordering above.
- `VerifiesCybersourceWebhook` — validates the `v-c-signature` header (`t={ms};keyId={id};sig={base64}`), HMAC-SHA256 over `{t}.{rawBody}` with the base64-decoded webhook secret, rejects notifications older than 300s, constant-time compare.
- `VerifiesResultJwt` — cryptographically verifies the UC v1 orchestrated-payment result JWT (RS256) using the RS256 public key embedded in the capture-context `flx.jwk`; checks exp/iat/nbf with leeway, validates issuer/order-reference/amount, extracts reusable TMS token ids for stored-credential charges (wallets yield none).

**Enums (`Enums/`):**
- `CybersourceEndpoint` (string paths, `path(string $id='')` substitutes `{id}`): `CaptureContexts` `/up/v1/capture-contexts`, `Payments` `/pts/v2/payments`, `Captures` `/pts/v2/payments/{id}/captures`, `Refunds` `/pts/v2/payments/{id}/refunds`, `Voids` `/pts/v2/payments/{id}/voids`, `Reversals` `/pts/v2/payments/{id}/reversals`, `CurrencyConversion` `/vas/v1/currencyconversion`, `Authentications` `/risk/v1/authentications`, `AuthenticationResults` `/risk/v1/authentication-results`, `InstrumentIdentifiers` `/tms/v1/instrumentidentifiers`, `Customers` `/tms/v2/customers`, `CustomerPaymentInstruments` `/tms/v2/customers/{id}/payment-instruments`, `TransactionDetails` `/tss/v2/transactions/{id}`, `TransactionSearch` `/tss/v2/searches`.
- `CybersourcePaymentType` — capture-context payment-type allowlist: `PANENTRY`, `GOOGLEPAY`, `APPLEPAY`, `CLICKTOPAY`; `allValues()` helper.
- `CybersourceTransactionStatus` → `PaymentStatus`: `Authorized`/`PartialAuthorized`/`AuthorizedPendingReview` → Authorized; `Captured` → Captured; `Pending`/`PendingAuthentication`/`Transmitted` → Pending; `Reversed` → Reversed; `Voided` → Voided; `Declined`/`AuthenticationFailed` → Declined; `InvalidRequest`/`ServerError` → Failed. `toPaymentStatusOrFailed(?string)` defaults to Failed.

**Payloads (`Payloads/`, request builders):** `CaptureContextPayload` (UC widget config: mandate, allowed networks/types, amount, billTo, optional completeMandate), `CapturePayload` (settle auth), `ClientReference` (derives `clientReferenceInformation.code`, ≤50 chars), `CurrencyConversionPayload` (DCC rate inquiry), `DccAmountDetails` (DCC amountDetails fragment), `DeviceInformation` (Decision Manager fingerprint fragment), `PayerAuthEnrollPayload` (3DS enroll), `PayerAuthValidatePayload` (3DS validate), `PaymentPayload` (charge from transient token), `RefundPayload`, `ReversalPayload`, `SearchPayload` (TSS search), `StoredCredentialPayload` (card-on-file charge), `TokenizePayload` (3-step TMS vaulting: `instrumentIdentifier()`, `customer()`, `paymentInstrument()`), `VoidPayload` (client reference only; id in path).

---

## Fawry

- **Driver:** `FawryGateway` extends `AbstractPaymentGateway`. Resolved by `GatewayName::Fawry` (`fawry`). `createCheckoutSession` dispatches by payment method to hosted / card / wallet / reference / MyFawry / installment flows.
- **CheckoutOptions:** `FawryCheckoutOptions` (readonly). Fields (all default `null`): `card: ?FawryCard` (raw card for card/installment charge), `walletNumber: ?string` (MWALLET), `installmentPlanId: ?string` (bank plan for CARD installment), `customerEmail: ?string`, `customerMobile: ?string`, `webhookUrl: ?string` (server-to-server order webhook). `toArray()` keys (nulls dropped): `card`, `wallet_number`, `installment_plan_id`, `customer_email`, `customer_mobile`, `webhook_url`.
- **Client — `FawryClient`:** hosts split by mode — staging `https://atfawry.fawrystaging.com` (hosted init) / `https://atfawry.fawrystaging.com/ECommerceWeb/` (charge, refund, capture, cancel, status); production `https://atfawry.com` / `https://www.atfawry.com/ECommerceWeb/`. Auth = a `signature` field **inside the JSON body** (built by `FawrySignature`), not a header. `Content-Type`/`Accept: application/json`.

**Operations:** `createCheckoutSession`, `capture` (Auth/Capture settlement), `refund`, `void` (cancel uncaptured auth), `getTransaction` (Get Payment Status V2), `verifyWebhook` (Server Notification V2, SHA-256).

**Signature — `FawrySignature`:** SHA-256 of ordered fields concatenated (no separators; empty string for absent optionals; amounts via `number_format((float)$amount, 2, '.', '')`). Per-flow field order — `card()`: merchantCode + merchantRefNum + customerProfileId + paymentMethod + amount + cardNumber + cardExpiryYear + cardExpiryMonth + cvv + returnUrl + secureKey; `wallet()`: + debitMobileWalletNo; `reference()`: through amount + secureKey; `hostedInit()`: merchantCode + merchantRefNum + returnUrl + (itemId+quantity+price…) + secureKey; `refund()`: merchantCode + referenceNumber + refundAmount + reason + secureKey; `installmentCard()`: card fields + installmentPlanId + secureKey; `capture()`: merchantRefNum + captureAmount + merchantCode + secureKey; `cancelAuthorization()`: merchantRefNum + merchantCode + secureKey; `status()`: merchantCode + merchantRefNumber + secureKey; `webhook()`: fawryRefNumber + merchantRefNumber + paymentAmount + orderAmount + orderStatus + paymentMethod + paymentReferenceNumber + secureKey.

**`FawryCard`** (readonly): `number`, `expiryYear` (YY), `expiryMonth` (MM), `cvv`. `toArray()`: `number`, `expiryYear`, `expiryMonth`, `cvv`.

**Enums (`Enums/`):**
- `FawryEndpoint` (with `url(bool $testMode)`): `HostedInit` `/fawrypay-api/api/payments/init`, `Charge` `/Fawry/payments/charge`, `Refund` `/Fawry/payments/refund`, `PaymentCapture` `/api/payment/capture`, `PaymentCancel` `/api/payment/cancel`, `StatusV2` `/Fawry/payments/status/v2`.
- `FawryOrderStatus` → `PaymentStatus`: `PAID` → Captured; `NEW`/`UNPAID` → Pending; `REFUNDED`/`PARTIAL_REFUNDED` → Refunded; `CANCELED` → Voided; `EXPIRED`/`FAILED` → Failed. `toPaymentStatusOrFailed(?string)` defaults to Failed.
- `FawryPaymentMethod`: `Hosted`=`hosted` (SDK-internal Express Checkout selector), `Card`=`PayUsingCC`, `MobileWallet`=`MWALLET`, `PayAtFawry`=`PAYATFAWRY`, `MyFawry`=`MYFAWRY`, `CardInstallment`=`CARD`. `fromRequest(?string)` defaults to `Hosted`.

**Payloads (`Payloads/`):** `FawryCancelPayload` (auth cancellation/void), `FawryCapturePayload` (settlement, optional partial amount), `FawryChargePayload` (per-method charge bodies: card/wallet/reference/MyFawry/installment, each adds its signature), `FawryFields` (static helper deriving common fields — merchant ref, description, language, customer, charge items — from the request), `FawryHostedPayload` (Express Checkout init; success response is a redirect URL as plain text), `FawryRefundPayload` (refund, optional reason).

---

## Paymob

- **Driver:** `PaymobGateway` extends `AbstractPaymentGateway`. Resolved by `GatewayName::Paymob` (`paymob`). `merchant_order_id` is the caller's order reference verbatim (no random suffix) so retries deduplicate on Paymob.
- **CheckoutOptions:** `PaymobCheckoutOptions`. Fields (all default `null`): `integrationId: int|string|null` (per-method integration id, overrides credentials), `iframeId: int|string|null` (iframe id for redirect URL), `expiration: ?int` (payment-key lifetime seconds; pipeline default 3600), `customerMobile: ?string` (billing). `toArray()` keys (nulls dropped): `integration_id`, `iframe_id`, `expiration`, `customer_mobile`. Also `fromArray()` for legacy arrays.
- **Client — `PaymobClient`:** base `https://accept.paymob.com/api`. **Bearer-token flow:** `authenticate()` POSTs `api_key` (the merchant key) to `/auth/tokens`, receives `token`, subsequent calls send `Authorization: Bearer {token}`. Endpoints: `/auth/tokens`, `/ecommerce/orders`, `/acceptance/payment_keys`, `/ecommerce/orders/transaction_inquiry`, `/acceptance/capture`, `/acceptance/void_refund/refund`, `/acceptance/void_refund/void`. JSON in/out; non-2xx → `GatewayRequestException`.

**Operations:** `createCheckoutSession`, `capture`, `refund`, `void`, `getTransaction` (order inquiry by order id), `searchTransaction` (inquiry by `merchant_order_id`), `verifyWebhook`.

**Checkout pipeline (`Checkout/`):** a Laravel `Illuminate\Pipeline\Pipeline` — `->send($context)->through([Authenticate, RegisterOrder, RequestPaymentKey, BuildCheckoutSession])->thenReturn()`. `PaymobCheckoutContext` carries readonly inputs (`request`, `client`, resolved `integrationId`, `iframeId`) and mutable outputs (`authToken`, `order`, `orderId`, `paymentToken`, `session`). Pipes (each `handle($ctx, Closure $next)`):
1. `Authenticate` — `client->authenticate()` → sets `authToken`.
2. `RegisterOrder` — POST `/ecommerce/orders` with `PaymobOrderPayload::build()` → sets `order`, `orderId`.
3. `RequestPaymentKey` — POST `/acceptance/payment_keys` with `PaymobPaymentKeyPayload::build()` (expiration from options or 3600) → sets `paymentToken`.
4. `BuildCheckoutSession` — assembles `CheckoutSession`; with `iframeId`, redirect URL `https://accept.paymob.com/api/acceptance/iframes/{iframeId}?payment_token={token}`, else `null`.

**HMAC — `PaymobHmac`:** HMAC-SHA512 (lowercase hex) keyed by the webhook secret, over 20 transaction fields concatenated in fixed documented order (booleans → literal `"true"`/`"false"`, missing → `""`): `amount_cents`, `created_at`, `currency`, `error_occured`, `has_parent_transaction`, `id`, `integration_id`, `is_3d_secure`, `is_auth`, `is_capture`, `is_refunded`, `is_standalone_payment`, `is_voided`, `order.id`, `owner`, `pending`, `source_data.pan`, `source_data.sub_type`, `source_data.type`, `success`. HMAC accepted from the `hmac` header or `hmac` body field.

**Enums (`Enums/`):**
- `PaymobPaymentMethod`: `Card`=`card`, `Valu`=`valu`, `Installment`=`installment`; each keys the per-method integration/iframe id. `fromRequest(?string)` defaults to `Card`.
- `PaymobTransactionStatus` (static helper — Paymob has no single status string; decodes boolean flags): `is_refunded` → Refunded; `is_voided` → Voided; `success && !pending` → Captured; `pending` → Pending; else → Declined.

**Payloads (`Payloads/`):** `PaymobBillingData` (13-field `billing_data`, always present, missing → `"NA"`), `PaymobOrderPayload` (order registration; amount in minor units, `merchant_order_id` = order reference verbatim), `PaymobPaymentKeyPayload` (payment-key body binding order + integration + billing data → payment token; `lock_order_when_paid: false`).

---

## PayLink

- **Driver:** `PaylinkGateway` extends `AbstractPaymentGateway`. Resolved by `GatewayName::Paylink` (`paylink`). Vaulting uses PayLink's CyberSource-TMS vault; `iframe` option yields an embeddable checkout URL. Non-interface extra: `deleteToken(string): bool`.
- **CheckoutOptions:** `PaylinkCheckoutOptions` (readonly). Fields: `webhookUrl: ?string` (=null), `orderDetails: ?string` (=null, free text on hosted page), `paymentMode: ?string` (=null), `iframe: bool` (=false, unsigned request field for embeddable URL), `idempotencyKey: ?string` (=null, defaults to order reference). `toArray()` keys (nulls dropped; `iframe:false` renders null and drops out): `webhook_url`, `order_details`, `payment_mode`, `iframe`, `idempotency_key`. Also `fromRequest()`/`fromArray()`.
- **Client — `PaylinkClient`:** URL `https://{GatewayCredentials::host}{endpoint}`. **Token + per-request HMAC-SHA256 signature, both in the JSON body** (not headers): public `token` (merchantId) plus computed `signature`. Optional `Idempotency-Key` header (from idempotency key or order reference). Successful JSON is unwrapped: returns the `data` array when present, else the whole response; non-2xx → `GatewayRequestException`.

**Operations:** `createCheckoutSession`, `capture`, `refund`, `void`, `reverseAuthorization`, `vaultInstrument` (token returned as both `instrumentIdentifierId` and `paymentInstrumentId`), `chargeStoredCredential`, `getTransaction` (check-status), `verifyWebhook` (body-embedded HMAC, no header).

**Signing — `PaylinkSignature` / `PaylinkSignedBody`:** signature = `base64(hmac_sha256(concatenated_ordered_values, hashToken, true))`, byte-compatible with the PayLink server and sibling SDKs. `PaylinkSignature::build($orderedValues, $hashToken)`; `coerce($value)` renders scalars to wire form (null→`''`, bool→`'1'`/`'0'`, int→decimal, float→shortest round-trip / throws on non-finite, string→itself); `equals()` uses `hash_equals`. `PaylinkSignedBody::build($endpoint, $params, $publicToken, $hashToken)` walks the endpoint's ordered fields, coerces (skips null optionals), collects signed values, appends `token` + `signature`.

**`PaylinkEndpoint`** (enum; backing value = path; `fields()` returns ordered `['name','signed']` field defs per endpoint, matching FormRequest order): `InvoiceCreate` `/api/v2/integration/init`, `Void` `/api/integration/void`, `Refund` `/api/integration/refund`, `Settle` `/api/integration/settle`, `ReverseAuthorization` `/api/integration/reverse-authorization`, `CheckStatus` `/api/integration/check-status`, `TokenizeCard` `/api/v2/integration/tokens/card`, `ChargeToken` `/api/v2/integration/tokens/charge`, `RevokeToken` `/api/v2/integration/tokens/revoke`. (E.g. `InvoiceCreate` signs first_name, last_name, email, order_title, order_amount, address, city, country, state, currency, redirection_url, webhook_url, order_details; leaves payment_mode + iframe unsigned.)

**Enums (`Enums/`):** `PaylinkPaidStatus` — `toPaymentStatus(?string)`: `PAID` → Captured; `AUTHORIZED` → Authorized; `UNPAID`/`PENDING` → Pending; `VOIDED` → Voided; `REVERSED` → Reversed; `REFUNDED`/`PARTIALLY_REFUNDED` → Refunded; `DECLINED` → Declined; all others → Failed.

---

## PayTabs

- **Driver:** `PaytabsGateway` extends `AbstractPaymentGateway`. Resolved by `GatewayName::Paytabs` (`paytabs`). `createCheckoutSession` selects integration type by payment method (invoice / managed / paylink / hosted-or-auth default). Deterministic request building (cart id = order reference) → PayTabs treats retries idempotently. Non-interface extra: `deleteToken(string): bool`.
- **CheckoutOptions:** `PaytabsCheckoutOptions`. Fields: `tranClass: ?string` (=null; defaults to `ecom`, e.g. `moto`), `webhookUrl: ?string` (=null, IPN), `tokenise: ?int` (=null; format 1–6 to save a reusable token), `iframe: bool` (=false), `framedReturnTop: ?bool` (=null), `framedReturnParent: ?bool` (=null), `framedMessageTarget: ?string` (=null, HTTPS `postMessage` target), `agreement: ?PaytabsAgreement` (=null, repeat billing), `splitPayout: array<PaytabsSplitPayout>` (=[]), `lineItems: array<PaytabsLineItem>` (=[]; empty → one line for full amount). `toArray()` keys (nulls dropped): `tran_class`, `webhook_url`, `tokenise`, `iframe`, `framed_return_top`, `framed_return_parent`, `framed_message_target`, `agreement`, `split_payout`, `line_items`.
- **Client — `PaytabsClient`:** base `https://{GatewayCredentials::host}` (region host, e.g. `secure.paytabs.sa`, `secure-egypt.paytabs.com`, `secure.paytabs.com`). Auth = **server key in the `Authorization` header** (`sharedSecret`); merchant identified by `profile_id` in the JSON body. No request signing. JSON in/out.

**Operations:** `createCheckoutSession`, `charge` (Own Form, browser payment_token S2S), `chargeStoredCredential` (saved token, MIT vs CIT), `capture` (partial-capable), `refund`, `void`, `reverseAuthorization` (`release`), `getTransaction` (`/payment/query`), `verifyWebhook`.

**Signature — `PaytabsSignature`:** HMAC-SHA256 (lowercase hex) over all callback fields except `signature`, empty values dropped (`array_filter`), sorted by key (`ksort`), rendered via `http_build_query`, keyed by the server key. `expected()` computes; `verify()` constant-time compares the posted `signature`.

**Value objects:** `PaytabsAgreement` (repeat-billing schedule: description, repeatAmount, repeatTerms, repeatPeriod, repeatEvery, firstInstallmentDueDate), `PaytabsBeneficiary` (split-payout bank details: name, accountNumber/IBAN, country, bank), `PaytabsLineItem` (sku, description, unitCost, quantity), `PaytabsSplitPayout` (stakeholder: entityId, entityName, itemDescription, itemTotal, mscFlag, beneficiary).

**Enums (`Enums/`):**
- `PaytabsEndpoint` (backing value = path): `Request` `/payment/request` (payments/captures/refunds/voids/hosted/invoice/managed/own-form/stored-credential — distinguished by `tran_type`), `Query` `/payment/query`, `LinkCreate` `/payment/link/create`, `TokenDelete` `/payment/token/delete`.
- `PaytabsResponseStatus` → `PaymentStatus`: `A` Authorised → Captured (a `sale` returning `A` is captured); `H` Hold → Authorized; `P` → Pending; `V` Voided / `C` Cancelled → Voided; `D` → Declined; `E` Error / `X` Expired → Failed. `toPaymentStatusOrFailed(?string)` defaults to Failed; `isApproved(?string)` true for `A`/`H`.

**Payloads (`Payloads/`):** `PaytabsPayload` — static factory for PT2 bodies: `hosted()`, `invoice()`, `managed()`, `payLink()`, `ownForm()`, `storedCredential()`, `capture()`, `refund()`, `void()`, `release()`, `query()`, `deleteToken()` (each drops null fields; every body carries `profile_id`; new payments set `tran_type`).

---

## PayPal

- **Driver:** `PayPalGateway` extends `AbstractPaymentGateway`. Resolved by `GatewayName::PayPal` (`paypal`). Orders v2 + Payments v2 + Vault v3.
- **CheckoutOptions:** `PayPalCheckoutOptions`. Fields (all default `null`): `cancelUrl: ?string` (falls back to request return URL), `brandName: ?string`, `locale: ?string` (BCP-47, falls back to request locale), `shippingPreference: ?PayPalShippingPreference` (default `NO_SHIPPING`), `userAction: ?PayPalUserAction` (default `PAY_NOW`), `paymentMethodPreference: ?PayPalPaymentMethodPreference`. `toArray()` keys (nulls dropped; enums via `->value`): `cancel_url`, `brand_name`, `locale`, `shipping_preference`, `user_action`, `payment_method_preference`.
- **Client — `PayPalClient`:** host from `GatewayCredentials::host` (`api-m.sandbox.paypal.com` / `api-m.paypal.com`). **OAuth2 client-credentials:** `authorization()` sends `merchantId`+`sharedSecret` via HTTP Basic to `/v1/oauth2/token` with `grant_type=client_credentials`, extracts `access_token`, **caches it on the instance** for reuse, attaches `Authorization: Bearer {token}` to all calls. Non-2xx → `GatewayRequestException`.

**Operations:** `createCheckoutSession` (create order, return approval URL + order id), `charge` (complete approved order — capture if `request->capture`, else authorize), `capture` (`/v2/payments/authorizations/:id/capture`), `refund` (`/v2/payments/captures/:id/refund`), `void` (`/v2/payments/authorizations/:id/void`), `vaultInstrument` (setup-token → payment-token), `chargeStoredCredential` (order with `payment_source.card.vault_id` + stored-credential metadata), `getTransaction`, `verifyWebhook`.

**Enums (`Enums/`):**
- `PayPalEndpoint` (`path(string $id)` substitutes `:id`): `OAuthToken` `/v1/oauth2/token`, `Orders` `/v2/checkout/orders`, `Order` `/v2/checkout/orders/:id`, `OrderCapture` `…/:id/capture`, `OrderAuthorize` `…/:id/authorize`, `AuthorizationCapture` `/v2/payments/authorizations/:id/capture`, `AuthorizationVoid` `/v2/payments/authorizations/:id/void`, `CaptureRefund` `/v2/payments/captures/:id/refund`, `SetupTokens` `/v3/vault/setup-tokens`, `PaymentTokens` `/v3/vault/payment-tokens`, `VerifyWebhookSignature` `/v1/notifications/verify-webhook-signature`.
- `PayPalOrderStatus` (order-level) → `PaymentStatus`: `CREATED`/`SAVED`/`APPROVED`/`PAYER_ACTION_REQUIRED` → Pending; `COMPLETED` → Captured; `VOIDED` → Voided. `toPaymentStatusOrFailed(?string)` → Failed.
- `PayPalPaymentStatus` (capture/authorization/refund resource) → `PaymentStatus`: `CREATED` → Authorized; `CAPTURED`/`PARTIALLY_CAPTURED` → Captured; `COMPLETED` → **context-sensitive** via `toPaymentStatus(PaymentStatus $completedAs = Captured)` (capture→Captured, refund→Refunded); `PENDING` → Pending; `PARTIALLY_REFUNDED`/`REFUNDED` → Refunded; `DECLINED`/`DENIED` → Declined; `VOIDED`/`CANCELLED` → Voided; `EXPIRED`/`FAILED` → Failed.
- `PayPalPaymentMethodPreference`: `UNRESTRICTED`, `IMMEDIATE_PAYMENT_REQUIRED`.
- `PayPalUserAction`: `PAY_NOW`, `CONTINUE`.
- `PayPalShippingPreference`: `NO_SHIPPING`, `GET_FROM_FILE`, `SET_PROVIDED_ADDRESS`.

**Payloads (`Payloads/`):** `PayPalPayload` — static builders: `order()` (Orders v2 create: intent CAPTURE/AUTHORIZE, purchase_units, experience_context), `storedCredentialOrder()` (vault_id + stored-credential metadata: MERCHANT/CUSTOMER initiator, RECURRING/ONE_TIME, FIRST/SUBSEQUENT), `captureAuthorization()` (`final_capture: true`), `refundCapture()` (amount, note_to_payer), `setupToken()` (card + billing_address, `YYYY-MM` expiry), `paymentToken()` (references setup token), `verifyWebhookSignature()` (transmission metadata + event). Null fields dropped.

**Webhook verification:** POST transmission metadata (`PayPal-Transmission-Id/-Time/-Sig`, `PayPal-Cert-Url`, `PayPal-Auth-Algo`) + the event body to `/v1/notifications/verify-webhook-signature` with `webhookId` = credentials' `webhookSecret`; `verified` iff response `verification_status === 'SUCCESS'`.

---

## Mpgs (Mastercard Payment Gateway Services)

- **Driver:** `MpgsGateway` extends `AbstractPaymentGateway`. Resolved by `GatewayName::Mpgs` (`mpgs`). Uses traits `MapsMpgsResponses`, `ResolvesMpgsIdentifiers`, `VerifiesMpgsWebhook`. Two-level identity (order id + transaction id); re-PUTting the same pair is MPGS's native idempotency.
- **CheckoutOptions:** `MpgsCheckoutOptions` (readonly, all fields `?string` default `null`): `operation` (PURCHASE / AUTHORIZE / VERIFY / NONE; default PURCHASE), `merchantName`, `merchantUrl`, `returnUrl`, `checkoutMode` (WEBSITE / PAYMENT_LINK; default WEBSITE). `toArray()` keys (nulls dropped): `operation`, `merchant_name`, `merchant_url`, `return_url`, `checkout_mode`.
- **Client — `MpgsClient`:** URL `https://{host}/api/rest/version/{version}/merchant/{merchantId}{endpoint}`, default version `100` (overridable via credential extra `api_version`). Auth = **HTTP Basic**: username `merchant.{merchantId}`, password `{sharedSecret}` (API password). Methods `post`, `put`, `get`, `tryGet` (→ null on 404).

**Operations:** every interface op except `requestDccRate` — `createCheckoutSession`, `charge`, `capture`, `refund`, `void`, `reverseAuthorization`, `enrollPayerAuth`, `validatePayerAuth`, `vaultInstrument`, `chargeStoredCredential`, `getTransaction`, `searchTransaction`, `verifyWebhook`.

**Concerns (`Concerns/`):** `MapsMpgsResponses` (folds decoded JSON into PaymentResult/RefundResult/TransactionSnapshot/PayerAuthResult; resolves status via `MpgsResult` + `MpgsGatewayCode`), `ResolvesMpgsIdentifiers` (derives order id from `orderReference`, transaction id from `idempotencyKey`, generating when absent; enforces order reference for existing-order ops), `VerifiesMpgsWebhook` (constant-time compare of the `X-Notification-Secret` header against the configured secret — header secret, not HMAC).

**Enums (`Enums/`):**
- `MpgsApiOperation` (wire `apiOperation` strings): `INITIATE_CHECKOUT`, `PAY`, `AUTHORIZE`, `CAPTURE`, `REFUND`, `VOID`, `VERIFY`, `INITIATE_AUTHENTICATION`, `AUTHENTICATE_PAYER`.
- `MpgsEndpoint` (`path(array $params)` substitutes `{key}`, URL-encoded): `Session` `/session`, `SessionById` `/session/{sessionId}`, `Order` `/order/{orderId}`, `Transaction` `/order/{orderId}/transaction/{transactionId}`, `Token` `/token`, `TokenById` `/token/{tokenId}`.
- `MpgsGatewayCode` (`response.gatewayCode`, refines failures) → `PaymentStatus`: `APPROVED`/`APPROVED_PENDING_SETTLEMENT` → Captured; `DECLINED`/`EXPIRED_CARD`/`INSUFFICIENT_FUNDS`/`BLOCKED`/`REFERRED_TO_ISSUER`/`DECLINED_DO_NOT_CONTACT`/`DECLINED_PAYMENT_PLAN`/`NOT_ENROLLED_3D_SECURE` → Declined; `TIMED_OUT`/`ACQUIRER_SYSTEM_ERROR`/`SYSTEM_ERROR`/`UNSPECIFIED_FAILURE` → Failed. `toPaymentStatusOrFailed(?string)` → Failed.
- `MpgsOrderStatus` (order-level `status`) → `PaymentStatus`: `AUTHORIZED` → Authorized; `CAPTURED`/`PARTIALLY_CAPTURED`/`DISBURSED` → Captured; `REFUNDED`/`PARTIALLY_REFUNDED`/`EXCESSIVELY_REFUNDED` → Refunded; `VOIDED`/`CANCELLED` → Voided; `PENDING`/`VERIFIED` → Pending; `DECLINED` → Declined; `FAILED`/`CHARGEBACK_PROCESSED` → Failed. `toPaymentStatusOrFailed(?string)` → Failed.
- `MpgsResult` (top-level `result`): `SUCCESS` (apply caller's success status), `PENDING`, `FAILURE`, `UNKNOWN`; `isSuccessful()`, `fromResponse(?string)` → `Unknown`.

**Payloads (`Payloads/`):** `CapturePayload` (`CAPTURE`; amount+currency, order in URL), `ChargePayload` (`PAY`/`AUTHORIZE` for session charge; amount, session id, optional billing), `HostedCheckoutPayload` (`INITIATE_CHECKOUT` session: interaction + order), `MpgsPayloadParts` (shared fragments `order()`, `transactionAmount()`, `cardSourceOfFunds()`, `billing()`, `device()` 3DS browser data), `PayerAuthenticatePayload` (`AUTHENTICATE_PAYER`), `PayerAuthInitiatePayload` (`INITIATE_AUTHENTICATION`; channel `PAYER_BROWSER`), `RefundPayload` (`REFUND`), `StoredCredentialPayload` (`PAY` card-on-file: token, `storedOnFile` TO_BE_STORED/STORED, optional MIT agreement), `TokenizePayload` (`/token` POST: card sourceOfFunds + optional billing), `VoidPayload` (`VOID`; shared by void and reversal — SDK status differs).

---

## Authorize.Net

- **Driver:** `AuthorizeNetGateway` extends `AbstractPaymentGateway`. Resolved by `GatewayName::AuthorizeNet` (`authorize_net`). Charges consume an Accept.js opaque-data nonce; vaults into a Customer Information Manager (CIM) profile (opaque data or raw card) for MIT/CIT stored-credential charges. No hosted checkout, 3DS, DCC, reversal, or search.
- **CheckoutOptions:** none (no hosted/embedded checkout).
- **Client — `AuthorizeNetClient`:** single JSON endpoint `https://{GatewayCredentials::host}/xml/v1/request.api` (`api.authorize.net` / `apitest.authorize.net`). Auth = body-level `merchantAuthentication` (no header): `name` = API Login ID (`merchantId`), `transactionKey` = `sharedSecret`, injected into every envelope. Methods `createTransaction()` (createTransactionRequest), `getTransactionDetails()`, and `createCustomerProfile()` (createCustomerProfileRequest; `validationMode` follows `testMode`). Strips UTF-8 BOM and unwraps the `*Response` root; HTTP 200 = transport success (business declines/errors live in the body); non-2xx → `GatewayRequestException`.

**Operations:** `charge` (auth-only or authCapture per `request->capture`), `capture` (priorAuthCapture), `refund` (referenced), `void`, `vaultInstrument` (CIM create-profile from `transientToken` opaque data or raw card → `VaultedInstrument{customerId=customerProfileId, paymentInstrumentId=paymentProfileId}`), `chargeStoredCredential` (profile charge; needs both ids; `initiator` → `processingOptions.isSubsequentAuth` (MIT) / `isStoredCredentials` (CIT)), `getTransaction` (reconciliation lookup), `verifyWebhook`.

**Enums (`Enums/`):** `AuthorizeNetTransactionStatus` (maps `getTransactionDetails` `transactionStatus`) → `PaymentStatus`: `authorizedPendingCapture`/`FDSAuthorizedPendingReview` → Authorized; `capturedPendingSettlement`/`settledSuccessfully` → Captured; `voided` → Voided; `refundPendingSettlement`/`refundSettledSuccessfully` → Refunded; `declined` → Declined; `expired`/`generalError` → Failed; `FDSPendingReview` → Pending. `fromTransaction(array)` and unrecognized/null → **Pending** (treat as unresolved, not a guessed terminal outcome).

**Payloads (`Payloads/`):** `AuthorizeNetPayload` — static `transactionRequest` builders (array order is load-bearing: JSON→XML against an order-sensitive schema; empty optional blocks dropped): `charge()` (`authCaptureTransaction`/`authOnlyTransaction`; `payment.opaqueData` dataDescriptor `COMMON.ACCEPT.INAPP.PAYMENT` + Accept.js nonce; `order.invoiceNumber` ≤20 chars; optional `customer.email`, `billTo`), `capture()` (`priorAuthCaptureTransaction` + `refTransId`), `refund()` (`refundTransaction` + `refTransId`), `void()` (`voidTransaction` + `refTransId`, no amount), `createProfile()` (CIM `profile` block: `paymentProfiles.payment` = `opaqueData` when a `transientToken` is present else `creditCard` `YYYY-MM`; optional `merchantCustomerId`, `billTo`), `chargeProfile()` (`authCaptureTransaction` + `profile.customerProfileId`/`paymentProfile.paymentProfileId` + `processingOptions`).

**Webhook verification:** header `X-ANET-Signature: sha512={HEX}` (case-insensitive lookup, `sha512=` prefix stripped); compute `hash_hmac('sha512', $rawBody, $webhookSecret)` (uppercase hex), constant-time `hash_equals`; empty secret fails verification. Parses `eventType` + `payload.id`/`payload.responseCode` into a `WebhookEvent` (status by event type: `*refund*`→Refunded, `*void*`→Voided, `*authorization*` non-capture + responseCode 1→Authorized, else responseCode 1→Captured, responseCode≠1→Declined). Payload is inspectable even when unverified.

---

## Airwallex

- **Driver:** `AirwallexGateway` extends `AbstractPaymentGateway`. Resolved by `GatewayName::Airwallex` (`airwallex`). Airwallex "Online Payments" card flow built on PaymentIntents: `createCheckoutSession` creates a PaymentIntent and returns its id + `client_secret` for the client-side (Elements/drop-in) checkout, which collects the card and confirms the intent — so the interactive `charge` completes in the browser and is **not** a server operation. Server-side follow-ons act on the intent. No hosted-redirect-only flow, void, DCC, payer-auth, or raw-card vaulting.
- **CheckoutOptions:** none. Configured from `CheckoutSessionRequest` (a `metadata` key in the options bag is forwarded to the intent as a JSON object when non-empty).
- **Credentials:** `merchantId` = client id (`x-client-id`), `sharedSecret` = API key (`x-api-key`), `webhookSecret` = webhook HMAC secret; `extra.api_version` (`x-api-version`, default `2025-11-11`) and `extra.account_id` (`x-login-as`, connected-account scope) are optional. Host resolves by `testMode`: `api-demo.airwallex.com` (test) / `api.airwallex.com` (live).
- **Client — `AirwallexClient`:** URL `https://{host}{path}`. Auth = **API-access login**: `POST /api/v1/authentication/login` with `x-client-id`/`x-api-key` (+ optional `x-api-version`/`x-login-as`) returns a bearer `token`, cached per client instance (one login per gateway per request); every call also carries `x-client-id` and the optional scope headers. Non-2xx → `GatewayRequestException`. Amounts are exact **major-unit** decimals (Airwallex uses major units, not minor).

**Operations:** `createCheckoutSession` (create PaymentIntent; `paymentMethod === 'authorize'` → `capture_method: manual` hold, else `automatic`; `CheckoutSession{reference=intent id, jwt=client_secret, redirectUrl=next_action.url}`), `charge` (create PaymentIntent then confirm with `payment_method.id` = the transient token, a client-tokenized PaymentMethod id; `capture` chooses automatic vs manual capture), `capture` (`/payment_intents/{id}/capture`, partial by amount), `refund` (`/refunds/create`; `payment_intent_id` + amount + optional `reason`), `void` & `reverseAuthorization` (both `/payment_intents/{id}/cancel`; full cancel only, no partial), `vaultInstrument` (`/payment_consents/create` then `/payment_consents/{id}/verify` with the card — needs `customerReference` = Airwallex customer id; returns `payment_consent_id`), `chargeStoredCredential` (create intent then confirm against `payment_consent_id` = `paymentInstrumentId`), `getTransaction` (`GET /payment_intents/{id}`), `searchTransaction` (`GET /payment_intents?merchant_order_id=…`, first match), `verifyWebhook`.

**Unsupported:** `requestDccRate` (Airwallex has no Online Payments DCC quote) and `enrollPayerAuth`/`validatePayerAuth` (3-D Secure runs inline during confirm via Netcetera, not as standalone enroll/validate steps) inherit `AbstractPaymentGateway`'s throwing behaviour. `vaultInstrument`'s card verification may itself require a 3-D Secure step before the consent is usable; the common alternative is a consent created client-side by Airwallex Elements, charged directly via `chargeStoredCredential`.

**Enums (`Enums/`):** `AirwallexEndpoint` (string paths, `path(string $id='')` substitutes `:id`): `Login` `/api/v1/authentication/login`, `PaymentIntents` `/api/v1/pa/payment_intents/create`, `PaymentIntentList` `/api/v1/pa/payment_intents`, `PaymentIntent` `…/:id`, `PaymentIntentConfirm` `…/:id/confirm`, `PaymentIntentCapture` `…/:id/capture`, `PaymentIntentCancel` `…/:id/cancel`, `Refunds` `/api/v1/pa/refunds/create`, `Refund` `/api/v1/pa/refunds/:id`, `PaymentConsents` `/api/v1/pa/payment_consents/create`, `PaymentConsentVerify` `…/:id/verify`. `AirwallexIntentStatus` → `PaymentStatus`: `REQUIRES_PAYMENT_METHOD`/`REQUIRES_CUSTOMER_ACTION` → Pending; `REQUIRES_CAPTURE` → Authorized; `SUCCEEDED` → Captured; `CANCELLED` → Voided; `toPaymentStatusOrFailed(?string)` defaults to Failed.

**Payloads (`Payloads/`):** `AirwallexPayload` — static body builders (nulls dropped; amount = `(float) Money::toDecimalString()`): `createIntent()` (`request_id`/`merchant_order_id` = order reference, `amount`, `currency`, optional `return_url`/`descriptor`/`customer_id`/`metadata`, `payment_method_options.card.capture_method`), `chargeIntent()`/`confirmMethod()` (create then confirm a charge with `payment_method.id`), `storedCredentialIntent()`/`confirmConsent()` (saved-card charge; confirm against `payment_consent_id`), `cancel()` (`request_id` + optional `cancellation_reason`), `createConsent()`/`verifyConsent()` (vault: `customer_id` + `next_triggered_by`/`merchant_trigger_reason`, then `payment_method` card + `verification_options.card.currency`), `capture()` (`request_id` + `amount`), `refund()` (`request_id` + `payment_intent_id` + `amount` + optional `reason`). `request_id` prefers the idempotency key, then order reference, then transaction id.

**Webhook verification:** headers `x-timestamp` + `x-signature` (hex); compute `hash_hmac('sha256', $timestamp.$rawBody, $webhookSecret)`, constant-time `hash_equals`; empty secret/timestamp/signature fails verification. Parses event `name` + `data.object` into a `WebhookEvent` (transaction id = `object.id`; status from `object.status` via `AirwallexIntentStatus`, `refund.*` events by refund status, else derived from the event name). Payload is inspectable even when unverified.
