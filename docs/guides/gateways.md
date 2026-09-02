# Gateways

[← Back to the README](../../README.md)

## A sample per gateway

The same injected `PaymentGatewayFactory` drives every gateway. Each class below is
self-contained.

**CyberSource Unified Checkout** — mint a capture context for the widget (then charge
the transient token it returns, as in the `ChargeInvoice` example in the
[README quick start](../../README.md#quick-start)):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;

final readonly class StartCybersourceCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::CybersourceUnifiedCheckout)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(10000, 'EGP'),
                targetOrigins: ['https://shop.test'],
            ));

        // hand $session->jwt to the Unified Checkout widget on the front end
    }
}
```

For the **orchestrated (autoProcessing) flow** — pass a `completeMandate` so the widget
runs Decision Manager, 3-D Secure, authorization, and TMS tokenization client-side and
resolves with a signed result JWT, then verify that JWT server-side and trust it (no
`/pts/v2/payments` authorization and no transaction-search lag):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\ConfirmOrchestratedPaymentRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\MandateCompletionType;
use Hyprpay\Payments\Domain\ValueObject\Money;

$cybersource = $factory->make(GatewayName::CybersourceUnifiedCheckout);

// 1. Mint a capture context that tells the widget to auto-complete the payment.
$session = $cybersource->createCheckoutSession(new CheckoutSessionRequest(
    money: Money::minor(10000, 'EGP'),
    targetOrigins: ['https://shop.test'],
    orderReference: 'ORDER-123',
    completeMandate: MandateCompletionType::Capture,   // CAPTURE (sale) | AUTH (hold only)
));

// hand $session->jwt to checkout.mount(); it resolves with a signed result JWT.

// 2. Verify that result JWT against the capture context and trust the outcome.
$result = $cybersource->confirmOrchestratedPayment(new ConfirmOrchestratedPaymentRequest(
    resultJwt: $resultJwt,                 // returned by checkout.mount() on the front end
    captureContextJwt: $session->jwt,      // source of the RS256 verification key (flx.jwk)
    expectedMoney: Money::minor(10000, 'EGP'),
    orderReference: 'ORDER-123',
));
// $result->status is Captured (or Authorized for an AUTH mandate). For a real card,
// $result->instrumentIdentifierId / paymentInstrumentId / customerId hold the reusable TMS
// token for later stored-credential installments; a wallet sets $result->isWallet with no token.
```

**Fawry** — start a hosted checkout and redirect the payer:

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;

final readonly class StartFawryCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::Fawry)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(15000, 'EGP'),
                orderReference: 'ORDER-124',
                returnUrl: 'https://shop.test/return',
                customer: new Customer(email: 'ada@shop.test', firstName: 'Ada', lastName: 'Lovelace'),
                // paymentMethod: 'PAYATFAWRY' → $session->reference; 'PayUsingCC' / 'MWALLET' also supported
            ));

        // redirect the payer to $session->redirectUrl (hosted page)
    }
}
```

**Paymob** — runs the auth → order → payment-key flow and returns the iframe URL
(integration/iframe ids come from `GatewayCredentials::$extra` or, as here, `options`):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobCheckoutOptions;

final readonly class StartPaymobCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::Paymob)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(15000, 'EGP'),
                orderReference: 'ORDER-125',
                paymentMethod: 'card',
                customer: new Customer(email: 'ada@shop.test', firstName: 'Ada', lastName: 'Lovelace'),
                options: new PaymobCheckoutOptions(integrationId: 111111, iframeId: 222222, customerMobile: '01000000000'),
            ));

        // redirect to $session->redirectUrl (the Paymob iframe); Paymob order id is $session->reference
    }
}
```

**PayLink** — create an invoice and redirect to the hosted checkout (or pass
`options: new PaylinkCheckoutOptions(iframe: true)` to get an iframe-ready `redirectUrl` to embed instead):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Infrastructure\Gateway\Paylink\PaylinkCheckoutOptions;

final readonly class StartPaylinkCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::Paylink)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(25000, 'USD'),
                orderReference: 'ORDER-126',
                description: 'Gold Plan',
                returnUrl: 'https://shop.test/return',
                customer: new Customer(email: 'john@example.com', firstName: 'John', lastName: 'Doe'),
                options: new PaylinkCheckoutOptions(webhookUrl: 'https://shop.test/webhook', iframe: true),
            ));

        // embed $session->redirectUrl in an <iframe> (or redirect to it without iframe);
        // reconcile later by $session->reference (invoice id)
    }
}
```

In iframe mode the returned `redirectUrl` is embedded rather than redirected to. The
frame needs `allow="payment *"` (so Apple Pay / Google Pay work inside it), and the
checkout signals completion to the parent window via `postMessage` — a
`{ type: 'paylink_payment', success }` event from your PayLink origin — instead of
redirecting. The embedding page's origin must match your integration's registered
Origin, or the browser blocks framing.

```html
<iframe src="$session->redirectUrl" allow="payment *"
        style="width:100%;min-height:640px;border:0" title="Secure checkout"></iframe>
<script>
  addEventListener('message', function (e) {
    if (e.origin !== 'https://pay.getpayin.com') return;
    if (e.data?.type !== 'paylink_payment') return;
    window.location.href = e.data.success ? '/thank-you' : '/checkout?failed=1';
  });
</script>
```

**PayTabs** — start a hosted payment page and redirect the payer (pass
`paymentMethod: 'auth'` to place a hold you capture later, and `PaytabsCheckoutOptions::$webhookUrl`
for the server-to-server IPN callback):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\PaytabsCheckoutOptions;

final readonly class StartPaytabsCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): void
    {
        $session = $this->gateways
            ->make(GatewayName::Paytabs)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(12030, 'SAR'),
                orderReference: 'ORDER-127',
                description: 'Gold Plan',
                returnUrl: 'https://shop.test/return',
                customer: new Customer(email: 'john@example.com', firstName: 'John', lastName: 'Doe'),
                options: new PaytabsCheckoutOptions(webhookUrl: 'https://shop.test/ipn'),
            ));

        // redirect to $session->redirectUrl (the PayTabs hosted page);
        // reconcile later by $session->reference (the PayTabs tran_ref)
    }
}
```

**PayPal** — create an order and redirect the buyer to PayPal to approve it, then
complete the order once they return (pass `paymentMethod: 'authorize'` to place a hold
you capture later instead of capturing on approval):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\PayPalCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalUserAction;

$paypal = $factory->make(GatewayName::PayPal);

// 1. Create the order and send the buyer to PayPal to approve it.
$session = $paypal->createCheckoutSession(new CheckoutSessionRequest(
    money: Money::minor(10000, 'USD'),
    orderReference: 'ORDER-127',
    description: 'Gold Plan',
    returnUrl: 'https://shop.test/return',   // PayPal redirects here after approval
    options: new PayPalCheckoutOptions(      // typed, per-gateway options — no ambiguous array
        cancelUrl: 'https://shop.test/cancel',
        brandName: 'Example',
        userAction: PayPalUserAction::PayNow,
    ),
));

// redirect to $session->redirectUrl (PayPal's approval page);
// $session->reference is the PayPal order id — keep it for step 2.

// 2. After the buyer approves and PayPal redirects them back, complete the order.
$result = $paypal->charge(new ChargeRequest(
    transientToken: $session->reference,     // the approved PayPal order id
    money: Money::minor(10000, 'USD'),
));
// $result->status is Captured (or Authorized when charge sets capture: false),
// and $result->transactionId is the capture/authorization id for follow-ons.
```

**Mastercard MPGS** — create a Hosted Checkout session, then charge it against the
merchant-assigned order and transaction ids (`orderReference` → order id, `idempotencyKey`
→ transaction id; re-PUTting the same pair is MPGS's native idempotency):

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\MpgsCheckoutOptions;

$mpgs = $factory->make(GatewayName::Mpgs);

// 1. Create a Hosted Checkout session for the order.
$session = $mpgs->createCheckoutSession(new CheckoutSessionRequest(
    money: Money::minor(25000, 'USD'),
    orderReference: 'ORDER-128',                 // becomes the MPGS order id
    description: 'Goods and Services',
    options: new MpgsCheckoutOptions(
        operation: 'PURCHASE',                   // PURCHASE | AUTHORIZE | VERIFY
        merchantName: 'Example LLC',
        returnUrl: 'https://shop.test/return',
    ),
));

// launch Hosted Checkout on the front end with $session->reference (the session id),
// loading the checkout script from $session->clientLibrary.

// 2. Or charge a session the browser created directly — PAY (capture) or AUTHORIZE.
$result = $mpgs->charge(new ChargeRequest(
    transientToken: $sessionId,                  // the MPGS session id holding the card
    money: Money::minor(10000, 'USD'),
    orderReference: 'ORDER-128',                 // the MPGS order id
    idempotencyKey: 'txn-1',                     // becomes the MPGS transaction id
));
// $result->status is Captured (or Authorized when charge sets capture: false).
```

**Authorize.Net** — charge an Accept.js opaque-data token (the card is tokenised in the
browser, so no PAN reaches your server), then capture, refund, or void by transaction id:

```php
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;

$authnet = $factory->make(GatewayName::AuthorizeNet);

// Charge the Accept.js payment nonce (transientToken is the opaqueData dataValue).
$result = $authnet->charge(new ChargeRequest(
    transientToken: $opaqueDataValue,            // from Accept.js dispatchData()
    money: Money::minor(5000, 'USD'),            // 50.00 USD
    orderReference: 'ORDER-129',                 // Authorize.Net invoiceNumber / refId
    capture: true,                               // false = authorize only
));
// $result->status is Captured (or Authorized when capture: false); $result->transactionId is the transId.
```

Cards vault into a Customer Information Manager (CIM) profile — from the same Accept.js
token, so no PAN reaches your server — and charge later as a stored credential (MIT/CIT):

```php
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;

// Vault the card (PAN-free) — returns the customer + payment profile ids.
$vaulted = $authnet->vaultInstrument(new TokenizeInstrumentRequest(
    transientToken: $opaqueDataValue,            // or pass cardNumber/expirationMonth/expirationYear
));

// Charge the stored profile later (pass BOTH ids back).
$authnet->chargeStoredCredential(new StoredCredentialChargeRequest(
    paymentInstrumentId: $vaulted->paymentInstrumentId, // customerPaymentProfileId
    customerId: $vaulted->customerId,                   // customerProfileId
    money: Money::minor(9900, 'USD'),
    initiator: CredentialInitiator::Merchant,           // MIT (isSubsequentAuth); Customer → CIT (isStoredCredentials)
));
```

See the [Authorize.Net API reference](https://developer.authorize.net/api/reference/index.html)
for the underlying transaction types (`authCaptureTransaction`, `priorAuthCaptureTransaction`,
`refundTransaction`, `voidTransaction`) and Customer Information Manager (CIM).

**Airwallex** — create a PaymentIntent and hand the id + client secret to the Airwallex
client-side element (Elements / drop-in), which collects the card and confirms the
payment in the browser; the server then reconciles by intent id or via webhooks:

```php
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\Enum\GatewayName;

$airwallex = $factory->make(GatewayName::Airwallex);

$session = $airwallex->createCheckoutSession(new CheckoutSessionRequest(
    money: Money::minor(10000, 'USD'),           // 100.00 USD (sent to Airwallex as major units)
    orderReference: 'ORDER-130',                 // merchant_order_id + request_id (idempotent)
    returnUrl: 'https://shop.test/return',
    // paymentMethod: 'authorize',               // manual-capture hold; capture() settles it later
));
// Hand $session->reference (intent id) + $session->jwt (client_secret) to the Airwallex front end.
```

Once the intent is captured (`SUCCEEDED`), reconcile it, refund it, or charge a saved
card fully server-side against a stored PaymentConsent:

```php
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;

// Reconcile the intent by id.
$snapshot = $airwallex->getTransaction($session->reference);   // status Captured when SUCCEEDED

// Refund all or part of it.
$airwallex->refund(new RefundRequest(
    transactionId: $session->reference,          // the payment_intent_id
    money: Money::minor(2500, 'USD'),
    reason: 'Partial refund',
));

// Charge a saved card (creates an intent, then confirms it against the consent).
$airwallex->chargeStoredCredential(new StoredCredentialChargeRequest(
    paymentInstrumentId: $paymentConsentId,      // Airwallex PaymentConsent id
    money: Money::minor(9900, 'USD'),
    initiator: CredentialInitiator::Merchant,
    orderReference: 'ORDER-131',
));
```

A saved card is a **PaymentConsent**. `vaultInstrument` creates one server-side (a
consent-create then card-verify against the customer — pass the Airwallex customer id as
`customerReference`), and `chargeStoredCredential` charges the resulting `payment_consent_id`.
More commonly the consent is created **client-side** by Airwallex Elements (the PAN never
reaches your server), in which case you already hold the `payment_consent_id` and can skip
straight to `chargeStoredCredential`. Note the server-side vault's card verification may
require a 3-D Secure step before the consent is usable.

See the [Airwallex Online Payments API](https://www.airwallex.com/docs/api) for the
PaymentIntent lifecycle and the [webhook signing scheme](https://www.airwallex.com/docs/developer-tools/webhooks/listen-for-webhook-events)
(`x-timestamp` + `x-signature`, HMAC-SHA256) that `verifyWebhook()` validates.

Passing credentials explicitly per call — this skips the resolver, so it works for
any dynamic source (a one-off override, per-merchant, per-tenant, …):

```php
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;

$gateway = $factory->make(GatewayName::Fawry, new GatewayCredentials(
    host: 'atfawry.fawrystaging.com',
    merchantId: $merchantCode,
    apiKeyId: '',
    sharedSecret: $secureKey,
    testMode: true,
));
```

**Tamara** — start a "buy now, pay later" checkout and redirect the customer to Tamara's
hosted page:

```php
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\ValueObject\Money;

final readonly class StartTamaraCheckout
{
    public function __construct(private PaymentGatewayFactory $gateways) {}

    public function handle(): string
    {
        $session = $this->gateways
            ->make(GatewayName::Tamara)
            ->createCheckoutSession(new CheckoutSessionRequest(
                money: Money::minor(30000, 'SAR'), // 300.00 SAR, exact minor units
                orderReference: 'ORDER-1',
                returnUrl: 'https://shop.test/tamara/return',
            ));

        return (string) $session->redirectUrl; // send the customer to Tamara
    }
}
```

Tamara is a redirect flow: after the customer approves on the hosted page, Tamara sends an
`order_approved` webhook — call `authorise($orderId)` to confirm the order, then `capture(...)`
on fulfilment. `void(...)`/`reverseAuthorization(...)` cancel before capture and `refund(...)`
returns funds after it. The default payment plan (`PAY_BY_INSTALMENTS`) is configurable via
`gateway.gateways.tamara.extra.payment_type`. See the operation matrix note⁵ below for the flow.

## Gateways & operations

Every driver implements the same `PaymentGatewayInterface`. Operations a gateway does
not support throw `UnsupportedOperationException`, so you can rely on the same surface
everywhere.

| Operation | CyberSource UC | Fawry | Paymob | PayLink | PayTabs | PayPal | Mastercard MPGS | Authorize.Net | Airwallex | Tamara |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `createCheckoutSession` | ✅ capture context / orchestrated (autoProcessing) | ✅ hosted / card / wallet / pay-at-Fawry / MyFawry / instalment | ✅ iframe flow | ✅ invoice link / iframe | ✅ hosted / invoice / paylink / managed | ✅ order → approval redirect | ✅ hosted checkout session | — | ✅ PaymentIntent (client-side confirm) | ✅ hosted BNPL redirect |
| `charge` (transient token) | ✅ | — | — | — | ✅ Own Form (payment token) | ✅ complete approved order² | ✅ session (PAY / AUTHORIZE)³ | ✅ Accept.js opaque data | ✅ create + confirm (PaymentMethod id) | — |
| `chargeWallet` (Apple Pay / Google Pay) | ✅ tokenizedCard / fluidData | — | — | — | — | — | ✅ merchant-decrypted (devicePayment) | — | — | — |
| `confirmOrchestratedPayment` (verify result JWT) | ✅ RS256 (flx.jwk) | — | — | — | — | — | — | — | — | — |
| `capture` | ✅ | ✅ (Auth/Capture) | ✅ | ✅ (settle) | ✅ | ✅ | ✅ | ✅ | ✅ (manual-capture intent) | ✅ (after `authorise`)⁵ |
| `refund` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (simplified refund) |
| `void` | ✅ | ✅ (cancel auth) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (cancel intent) | ✅ (cancel order) |
| `reverseAuthorization` | ✅ | — | — | ✅ | ✅ (release) | ✅ (void of auth) | ✅ (void of auth) | — | ✅ (cancel intent) | ✅ (cancel order) |
| `setupPayerAuth` (3-DS device data collection) | ✅ | — | — | — | — | — | — | — | — | — |
| `enrollPayerAuth` / `validatePayerAuth` (3-DS) | ✅ | — | — | — | — | — | ✅ | — | — | — |
| `vaultInstrument` / `chargeStoredCredential` | ✅ (TMS, MIT/CIT) | — | — | ✅ vault + charge + revoke⁴ | ✅ token (MIT/CIT)¹ | ✅ vault (MIT/CIT) | ✅ token (MIT/CIT) | ✅ CIM (opaque/card, MIT/CIT) | ✅ PaymentConsent (vault + charge) | — |
| `requestDccRate` (Dynamic Currency Conversion) | ✅ | — | — | — | — | — | ✅ payment-options inquiry | — | — | — |
| `getTransaction` / `searchTransaction` | ✅ | ✅ | ✅ | ✅ | ✅ query | ✅ order lookup | ✅ order lookup | ✅ transaction details | ✅ intent lookup | ✅ order / reference lookup |
| `listTransactions` / `listTransactionsByReference` / `findSuccessfulTransactionByReference` (reconcile) | ✅ TSS | — | — | — | — | ✅ Reporting (≈31-day window, invoice match) | ✅ order transaction history | — | ✅ by `merchant_order_id` | ✅ find only |
| `verifyWebhook` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ API | ✅ notification secret | ✅ HMAC-SHA512 | ✅ HMAC-SHA256 | ✅ shared auth header |

Provider-specific inputs (e.g. Fawry payment method, Paymob integration/iframe ids,
card or wallet details) are passed through `CheckoutSessionRequest::$options` and the
`GatewayCredentials::$extra` bag. `$options` is a typed, per-gateway options DTO
implementing `CheckoutOptions` — `FawryCheckoutOptions`, `PaymobCheckoutOptions`,
`PaylinkCheckoutOptions`, `PaytabsCheckoutOptions`, and `PayPalCheckoutOptions` — so
every field is named and type-checked (including enums like `PayPalUserAction` and
nested value objects like `PaytabsAgreement` / `PaytabsSplitPayout` / `PaytabsLineItem`
and `FawryCard`) rather than stringly-typed. Each DTO's static `fromArray()` builds one
from a raw config array when you need to; each driver narrows `$options` to its own type.

For **CyberSource Unified Checkout**, the driver supports two flows. In the manual flow
`createCheckoutSession` mints a capture context, the widget returns a transient token, and
`charge` authorizes it server-side via `/pts/v2/payments`. In the orchestrated
(autoProcessing) flow, passing `completeMandate` (`CAPTURE` or `AUTH`) makes the widget run
Decision Manager, 3-D Secure, authorization, and TMS tokenization client-side and resolve
with a signed completed-payment result JWT; `confirmOrchestratedPayment` then
cryptographically verifies that JWT against the RS256 public key embedded in the capture
context (`flx.jwk`), validates its issuer, order reference, and amount, and returns the
outcome plus the reusable TMS token — making no `/pts/v2/payments` call and no
transaction-search lookup. A wallet result (Apple Pay / Google Pay) yields no reusable
credential, flagged via `OrchestratedPaymentResult::$isWallet`.

For a lighter-weight, build-your-own card form there is also **Flex Microform**:
`createMicroformSession` mints a Microform v2 capture context (`POST /microform/v2/sessions`)
that configures only the secure card fields — the browser origins allowed to launch Microform
and the accepted card networks — with no order amount or capture mandate. Load Microform.js
with the returned JWT, let the shopper type into the hosted card/expiry/CVV fields, and the
browser mints a transient token that flows through the same `charge` (or `enrollPayerAuth` /
`vaultInstrument`) path as the Unified Checkout widget — the amount is applied at charge time.
`createMicroformSession`, like `confirmOrchestratedPayment`, is a CyberSource-specific method
outside the shared `PaymentGatewayInterface`.

For billing that repeats on a schedule the gateway runs itself, the driver drives **CyberSource
Recurring Billing**. `chargeStoredCredential` bills a saved token once per call, leaving the
schedule to your own scheduler; `createSubscription` instead enrols an already-vaulted TMS
customer on a cadence CyberSource charges on its own (`POST /rbs/v1/subscriptions`). Nothing is
charged at create time — the first charge falls on the request's start date — and the cadence
comes from a `planId`, from an inline `billingPeriod`/`billingCycles`, or from both with the
inline values overriding the plan. `getSubscription`, `listSubscriptions`,
`updateSubscription`, `suspendSubscription`, `activateSubscription`, and `cancelSubscription`
drive it afterwards; suspending is reversible, cancelling is terminal. All seven are
CyberSource-specific methods outside the shared `PaymentGatewayInterface`, so call them on the
concrete driver.

```php
use Hyprpay\Payments\Domain\Command\CreateSubscriptionRequest;
use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\Money;

// Vault the card first — a subscription references a TMS customer and carries no card data.
$vaulted = $cybersource->vaultInstrument(new TokenizeInstrumentRequest(transientToken: $token));

$subscription = $cybersource->createSubscription(new CreateSubscriptionRequest(
    name: 'Pro monthly',
    customerId: $vaulted->customerId,
    startDate: '2026-10-01',               // UTC; a bare date is expanded to midnight UTC
    billingPeriod: BillingPeriod::monthly(),
    billingCycles: 12,                     // omit to bill until cancelled
    billingAmount: Money::minor(4999, 'USD'),
    orderReference: 'ORDER-9',
));

// $subscription->status is the subscription's own state (Pending until the first billing date);
// $subscription->requestStatus is CyberSource's verdict on the call itself (COMPLETED).

$cybersource->suspendSubscription($subscription->subscriptionId);                       // pause
$cybersource->activateSubscription($subscription->subscriptionId);                      // resume
$cybersource->cancelSubscription($subscription->subscriptionId);                        // terminal
```

`updateSubscription` amends a live subscription in place — a partial update, so untouched fields
keep their value. Its reach is narrower than a create's by CyberSource's own schema: the cycle
count and the amounts can change, but **the billing period and the currency cannot** (switching
cadence means cancelling and re-creating, and a subscription always bills in the currency it was
created with, so a `Money`'s currency is ignored here). It can also return `PENDING_REVIEW` —
accepted, but held rather than applied:

```php
use Hyprpay\Payments\Domain\Command\UpdateSubscriptionRequest;

$updated = $cybersource->updateSubscription(new UpdateSubscriptionRequest(
    subscriptionId: $subscription->subscriptionId,
    billingAmount: Money::minor(5999, 'USD'),   // re-price; the currency is fixed at create
    billingCycles: 24,                          // extend the run
));

$heldForReview = $updated->requestStatus === 'PENDING_REVIEW';
```

`listSubscriptions` (CyberSource's `getAllSubscriptions`) returns a **page**, not the whole book:
CyberSource defaults to 20 records and caps a page at 100, so walk it rather than assuming one
call is enough. Filters are optional and combine — filtering by `SubscriptionStatus::Delinquent`
is the practical way to find the subscriptions whose last rebill failed:

```php
use Hyprpay\Payments\Domain\Command\ListSubscriptionsRequest;
use Hyprpay\Payments\Domain\Enum\SubscriptionStatus;

$request = new ListSubscriptionsRequest(status: SubscriptionStatus::Delinquent, limit: 100);

do {
    $page = $cybersource->listSubscriptions($request);

    foreach ($page->subscriptions as $subscription) {
        // $subscription is a SubscriptionResult, same shape getSubscription() returns
    }

    $request = $request->nextPage();
} while ($page->hasMore());   // $page->totalCount is the size of the whole filtered set
```

A subscription CyberSource refuses comes back `success: false` with `requestStatus` `DECLINED` —
triage it with the same `DeclineClassifier` a declined charge uses, rather than blindly retrying
or asking every customer for a new card. `fromResult()` accepts a `SubscriptionResult` as well as
a `PaymentResult`, and `classify()` reads a raw response directly — which is how you triage a
failed rebill arriving on a verified webhook, the event that turns a subscription `DELINQUENT`:

```php
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\DeclineClassifier;

if (! $subscription->success) {
    $outcome = DeclineClassifier::fromResult($subscription);

    // $outcome->isRetryable() — false for an expired/invalid card or a "do not try again"
    // merchant-advice code, so stop and re-collect the card instead of burning the retry budget.
    // $outcome->customerMessage() — safe cardholder-facing copy, never a raw processor code.
}

// A failed rebill notification: classify the webhook payload the same way.
$event = $cybersource->verifyWebhook($rawBody, $headers);
$outcome = DeclineClassifier::classify($event->payload);
```

### Reporting

The driver also drives **CyberSource Reporting** (`/reporting/v3/*`) — reconciliation and
settlement files, either generated on demand or on a schedule the gateway runs. Generation is
asynchronous, so a report id existing does not mean a file exists: check the status before
downloading. Downloads are keyed by report **name and date** — the *end* of the period covered,
in the report's own timezone — never by report id, which is the usual cause of a 404 on a report
that plainly exists. `Report::downloadRequest()` derives all three correctly from a listed report.

```php
use Hyprpay\Payments\Domain\Command\CreateReportRequest;
use Hyprpay\Payments\Domain\Command\ListReportsRequest;
use Hyprpay\Payments\Domain\Enum\ReportDefinitionName;
use Hyprpay\Payments\Domain\Enum\ReportStatus;

// Queue a one-off report — CyberSource answers with an empty 201, so this returns bool.
$cybersource->createReport(new CreateReportRequest(
    name: 'settlement-sept',
    definitionName: ReportDefinitionName::TransactionRequest,   // or a raw string for a custom report
    startTime: '2026-09-01',                       // a bare date becomes midnight UTC
    endTime: '2026-09-30',
    fields: ['Request.RequestID', 'Request.TransactionDate'],
));

// Find it, then download it once it is ready.
$reports = $cybersource->listReports(new ListReportsRequest(
    startTime: '2026-09-01',
    endTime: '2026-09-30',
    status: ReportStatus::Completed,
    name: 'settlement-sept',
));

foreach ($reports as $report) {
    $download = $report->downloadRequest();   // null while still generating

    if ($download !== null) {
        $file = $cybersource->downloadReport($download);
        file_put_contents($file->filename(), $file->content);   // raw CSV/XML, unparsed
    }
}
```

`ReportStatus` distinguishes the three outcomes that matter: `isInProgress()` (`PENDING`,
`QUEUED`, `RUNNING`) means poll; `NO_DATA` is a *successful* run that matched nothing, so there
is no file to fetch; only `ERROR` is a failure.

For recurring files, subscribe instead of polling. The subscription endpoint is a PUT keyed by
report name, so creating one under an existing name **replaces** that schedule:

```php
use Hyprpay\Payments\Domain\Command\CreateReportSubscriptionRequest;
use Hyprpay\Payments\Domain\Enum\ReportDefinitionName;
use Hyprpay\Payments\Domain\Enum\ReportFrequency;

$cybersource->createReportSubscription(new CreateReportSubscriptionRequest(
    name: 'nightly-settlement',
    definitionName: ReportDefinitionName::TransactionRequest,
    fields: ['Request.RequestID'],
    startTime: '0200',                        // clock time of day as hhmm, not a date
    frequency: ReportFrequency::Daily,
    timezone: 'GMT',
));

$cybersource->listReportSubscriptions();
$cybersource->getReportSubscription('nightly-settlement');
$cybersource->deleteReportSubscription('nightly-settlement');   // future runs only
```

Reporting calls are scoped by organization, resolved from the request, else the
`organization_id` credential in the `extra` bag, else the merchant id — set `organization_id`
for a portfolio or partner account whose reports live under a different organization.

### Visa Bank Account Validation (BAVS)

`validateBankAccount` checks that a routing/account pair is a real, open account **before** an
ACH debit is attempted — how Nacha's account-validation mandate for WEB debits is met. It
authorises nothing and moves no money.

Read the two codes it returns separately. `resultCode` is the verdict on the account and only
`00` is documented as a pass, so `isValid()` treats every other value as not-validated rather
than guessing. `rawValidationCode` says whether the check could run at all: `-1` (unknown error)
and `-2` (service unavailable) are **inconclusive**, not a bad account — retry those rather than
rejecting the customer's details.

```php
use Hyprpay\Payments\Domain\Command\ValidateBankAccountRequest;

$result = $cybersource->validateBankAccount(new ValidateBankAccountRequest(
    routingNumber: '071000013',
    accountNumber: '4100',
    orderReference: 'ORDER-1',
));

match (true) {
    $result->isValid()        => $this->debitByAch(),
    $result->isInconclusive() => $this->retryLater(),   // service down — not a bad account
    default                   => $this->askForAnotherAccount(),
};

// Or validate an already-vaulted account by token, so the raw numbers never leave the vault:
// new ValidateBankAccountRequest(customerId: $vaulted->customerId)
```

Routing and account numbers are sensitive banking credentials. This operation sits outside
`PaymentGatewayInterface`, so the logging decorator never sees them.

### Corrections: voiding, crediting, and undoing a timeout

Three tiers, distinguished by **what the id addresses**.

`void`, `refund`, and `reverseAuthorization` are payment-scoped — CyberSource documents the first
as being for authorization and capture requested *together*. Once you capture separately the
capture becomes its own resource with its own id, and the payment path cannot reach it:

```php
$cybersource->voidCapture(new VoidRequest(transactionId: $captureId));
$cybersource->refundCapture(new RefundRequest(transactionId: $captureId, money: $money));
$cybersource->voidRefund(new VoidRequest(transactionId: $refundId));   // cancel a refund pre-settlement
$cybersource->voidCredit(new VoidRequest(transactionId: $creditId));   // the only way to undo a credit
```

| You did | Undo with |
| --- | --- |
| `charge()` (auth + capture together) | `void()` |
| `charge(capture: false)` then `capture()` | `voidCapture()` |
| `refund()` | `voidRefund()` |
| `creditPayment()` | `voidCredit()` |
| anything that **timed out** | `timeoutVoid()` / `timeoutReversal()` |

**`incrementAuthorization` raises an existing hold rather than placing a second one** — for hotels,
car rental, and any open-ended stay where the final bill is unknown when the card is presented.
The amount is the one to *add*, not the new total; passing the running total silently over-holds,
and authorizing again would withhold the funds twice.

```php
$cybersource->incrementAuthorization(new IncrementAuthorizationRequest(
    transactionId: $paymentId,
    additionalAmount: Money::minor(5000, 'USD'),   // adds $50 to the existing hold
));
```

**`creditPayment` is not a refund.** A refund returns part of a specific captured payment and is
bounded by it; a credit pushes money to a card with no originating transaction, no amount cap, and
nothing to tie it to. Processors watch credits — gate it behind the authorisation you would put on
a payout, not on a refund, and wire up `voidCredit` alongside it.

#### Undoing a request whose reply never arrived

When a call times out you cannot know whether it landed, and you have no transaction id to void
with. `timeoutVoid` and `timeoutReversal` take no resource id at all — they match on the merchant
transaction id from the **original** request.

```php
// On the original call. Without this, the timeout void below is impossible.
$cybersource->charge(new ChargeRequest(
    transientToken: $token,
    money: $money,
    merchantTransactionId: 'mtid-charge-1',
));

// … the request times out; you never receive a transaction id …

$cybersource->timeoutVoid(new TimeoutVoidRequest(merchantTransactionId: 'mtid-charge-1'));
```

`merchantTransactionId` is available on `ChargeRequest`, `CaptureRequest`, `RefundRequest`, and
`CreditRequest`, and **cannot be supplied retrospectively** — set it on anything you may need to
undo blind. Without a timeout *reversal*, a timed-out authorization strands a hold on the
cardholder's card until the issuer expires it, which can take days.

This complements rather than replaces reconciling by reference:
`findSuccessfulTransactionByReference` tells you *whether* a lost request settled and is eventually
consistent; a timeout void *undoes* it, and acts immediately.

Finally, `refreshPaymentStatus` asks CyberSource to re-check with the **processor**, for the
alternative payment methods that settle asynchronously — unlike `getTransaction`, which reads
CyberSource's own record. Reach for it when the record looks stale rather than merely unfinished.

### Managing webhook subscriptions

`verifyWebhook` verifies what arrives; these decide what is sent and where, so onboarding a
merchant's webhooks no longer needs the Business Center.

**Create the signing key first.** CyberSource signs every notification with it, and it is the
same secret `verifyWebhook` checks — a subscription created before the key exists has nothing to
sign with. The key is returned **once** and cannot be read back: store it as the
`webhook_secret` credential immediately.

```php
use Hyprpay\Payments\Domain\Command\CreateWebhookRequest;
use Hyprpay\Payments\Domain\Enum\WebhookSecurityType;
use Hyprpay\Payments\Domain\Enum\WebhookStatus;
use Hyprpay\Payments\Domain\ValueObject\WebhookProduct;

$key = $cybersource->createWebhookSecurityKey([
    'provider' => 'nrtd', 'tenant' => 'pxsecurity', 'keyType' => 'sharedSecret',
]);
$key->key;   // shown once — this is the webhook_secret credential

$cybersource->listWebhookProducts();   // what this account may subscribe to

$webhook = $cybersource->createWebhook(new CreateWebhookRequest(
    name: 'Payments',
    webhookUrl: 'https://shop.test/webhook',
    products: [new WebhookProduct('payments', ['payments.payments.accept'])],
    healthCheckUrl: 'https://shop.test/health',   // lets CyberSource resume it on its own
    securityType: WebhookSecurityType::Key,       // the signed scheme verifyWebhook() checks
    securityConfig: ['keyId' => $key->keyId],
));

$cybersource->testWebhook($webhook->webhookId);   // prove the wiring before it matters
$cybersource->setWebhookStatus($webhook->webhookId, WebhookStatus::Inactive);  // pause, keep config
```

Two things worth knowing. `isSignatureVerifiable()` is false for the oAuth security types — those
notifications are authenticated with a bearer token rather than a signature, so `verifyWebhook`
cannot check them. And a silent integration is often a subscription CyberSource quietly suspended
after repeated delivery failures, so `listWebhooks()` and `isDelivering()` are worth monitoring.

The retry policy's algorithm is easy to misread: with `firstRetry: 10` and `interval: 30`,
`Arithmetic` retries at 10, 40, and 70 minutes while `Geometric` retries at 10, 300, and 9,000 —
the difference between "later today" and "in six days".

### Card offers — knowing what the card is before you charge it

Offers keyed on card type — a Visa promotion, a premium-tier perk, an installment plan only some
issuers support — have to be decided **before** the authorization, and they carry real money, so
the answer has to be one the shopper cannot forge. `lookupBin` is that answer: it asks the
networks what the credential actually is.

```php
use Hyprpay\Payments\Domain\Command\BinLookupRequest;
use Hyprpay\Payments\Domain\Enum\CybersourceCardNetwork;

// Look the card up from the token the browser just minted — no PAN reaches your server.
$bin = $cybersource->lookupBin(BinLookupRequest::forTransientToken($transientToken));

if (! $bin->isResolved()) {
    // MULTIPLE or NO MATCH: the attributes cannot be trusted. Charge normally —
    // "unknown card" is never a reason to refuse a payment.
    return $this->chargeWithoutOffer();
}

$offer = match ($bin->network()) {
    CybersourceCardNetwork::Visa       => $this->visaPromotion(),
    CybersourceCardNetwork::Mastercard => $this->mastercardPromotion(),
    CybersourceCardNetwork::Amex       => $this->amexPromotion(),
    default                            => null,
};
```

`network()` returns a typed `CybersourceCardNetwork`, and deliberately so: the brand reaches the
SDK in three different shapes depending on where it came from — a numeric code (`001`) from BIN
lookup and the vault, a lowercase name (`visa`) from a verified orchestrated result, and an
uppercase name (`VISA`) from BIN lookup's brand field. `network()` collapses all of them, so the
same `match` works on a BIN lookup, a saved card, and a completed payment:

```php
$bin->network();          // from lookupBin()
$instrument->network();   // from a vaulted card
$result->network();       // from a verified orchestrated payment
```

Brand is often the *least* useful attribute for an offer, though. BIN lookup also tells you:

```php
$bin->fundingSource;                    // CardFundingSource::Credit | Debit | Prepaid …
$bin->platform?->isCommercial();        // consumer vs business/corporate/government
$bin->cardProduct;                      // "Visa Infinite" — premium-tier offers key on this
$bin->issuerCountry;                    // "US" — geo-gated promotions
$bin->supportsInstallments();           // only offer EMI where the issuer supports it
$bin->supports3ds();
$bin->fundingSource?->canPartiallyApprove();  // prepaid: expect partial approvals
```

**Do not use the transient token's own claims for this.** `ParsesTransientToken` decodes the JWT
payload without verifying its signature — the SDK's own docblock says as much — so a shopper could
edit it and claim an offer they are not entitled to. Ask the gateway instead.

### Vault lifecycle

`vaultInstrument` creates tokens; the rest of their life is managed separately. Reading a stored
instrument back is what lets a dead card be caught **at rest** rather than at charge time — an
expired or closed card behind a subscription would otherwise fail every rebill permanently, and
you would only learn of it from the decline.

```php
use Hyprpay\Payments\Domain\Command\UpdatePaymentInstrumentRequest;

$instrument = $cybersource->getPaymentInstrument($customerId, $paymentInstrumentId);

$instrument->expiry();                    // "12/2030"
$instrument->isExpired();                 // no network call — the vault already told us
$instrument->state?->isChargeable();      // false once the issuer closes the account
$instrument->maskedNumber;                // from the linked instrument identifier

// Every card a customer holds (paged: 20 by default, 100 max).
$page = $cybersource->listPaymentInstruments($customerId, limit: 100);
$page->default();                         // the instrument payments fall back to
```

**Re-dating beats re-collecting.** When a cardholder's card is reissued, updating the stored
expiry keeps every subscription and stored-credential charge already pointing at that instrument
working, with no new checkout:

```php
$cybersource->updatePaymentInstrument(new UpdatePaymentInstrumentRequest(
    customerId: $customerId,
    paymentInstrumentId: $paymentInstrumentId,
    expirationMonth: '01',
    expirationYear: '2032',
));
```

The card **number** is not updatable — it belongs to the instrument identifier behind the
instrument — so a genuinely different card is vaulted afresh. Deletion covers erasure requests:

```php
$cybersource->deletePaymentInstrument($customerId, $paymentInstrumentId);
$cybersource->deleteCustomer($customerId);              // and every instrument under them
$cybersource->deleteInstrumentIdentifier($identifierId); // purges the card itself
```

Two rules worth knowing: CyberSource also deletes the instrument identifier when no other
instrument references that card, and a customer's **default** instrument cannot be deleted while
they hold others — promote another first with `makeDefault: true`. Deleting a customer breaks any
subscription still billing them, so cancel those first.

### Plans

A plan is the reusable template a subscription is built from — cadence, cycle count, and price —
and is what `CreateSubscriptionRequest::$planId` points at. Without these, plans have to be built
by hand in the Business Center.

```php
use Hyprpay\Payments\Domain\Command\CreatePlanRequest;
use Hyprpay\Payments\Domain\Command\UpdatePlanRequest;
use Hyprpay\Payments\Domain\Enum\PlanStatus;

$plan = $cybersource->createPlan(new CreatePlanRequest(
    name: 'Pro monthly',
    billingPeriod: BillingPeriod::monthly(),
    billingAmount: Money::minor(4999, 'USD'),
    billingCycles: 12,
    status: PlanStatus::Draft,          // stage it; only an Active plan is subscribable
));

$cybersource->activatePlan($plan->planId);
$cybersource->listPlans();
$cybersource->deactivatePlan($plan->planId);   // closes it to NEW sign-ups only
$cybersource->deletePlan($plan->planId);       // only when nothing depends on it
```

One asymmetry to keep straight: **`updatePlan` can change the billing period, `updateSubscription`
cannot.** A plan is a template with nothing billing against it; a subscription is a live agreement.
A plan change governs subscriptions created afterwards — it does not retroactively re-price those
already running.

`listSubscriptionPayments($subscriptionId)` returns a subscription's settled, scheduled, and failed
payments, which is how a `DELINQUENT` subscription is diagnosed without waiting for the webhook —
hand the failed rebill to `DeclineClassifier::classify()`.

### Account Updater

The standing fix for recurring-billing churn. Account Updater asks the card networks whether the
cards behind your stored tokens have been reissued, re-dated, or closed, and pushes the answers
back into the vault. Only token ids are sent — no card number leaves the vault.

```php
use Hyprpay\Payments\Domain\Command\CreateAccountUpdaterBatchRequest;
use Hyprpay\Payments\Domain\Enum\AccountUpdaterBatchType;

$batch = $cybersource->createAccountUpdaterBatch(
    CreateAccountUpdaterBatchRequest::forTokenIds($tokenIds, merchantReference: 'NIGHTLY-2026-09-01'),
);

// Hours to days later — the networks answer on their own schedule.
$status = $cybersource->getAccountUpdaterBatchStatus($batch->batchId);

if ($status->isComplete() && $status->hasUpdates()) {
    $report = $cybersource->getAccountUpdaterBatchReport($batch->batchId);
    // reconcile the per-card changes; retire closed accounts instead of retrying them
}
```

Submission is asynchronous, so the create returns a batch id to poll, not results —
`isInProgress()` covers `RECEIVED`/`VALIDATED`/`PROCESSING`, `isFailed()` covers
`REJECTED`/`DECLINED`. Amex is a separate network flow: those cards must go in an
`AccountUpdaterBatchType::AmexRegistration` batch, not the `oneOff` batch Visa and Mastercard use.

### Discovering report definitions

`CreateReportRequest::$definitionName` and `$fields` are plain strings on purpose. Which report
definitions a merchant may run depends on their entitlements and subscription family, and the
**fields are a property of each definition** — a transaction report's columns are not a chargeback
report's. Freezing either into the SDK would reject reports a merchant is legitimately entitled to,
so both are discovered from the gateway:

```php
use Hyprpay\Payments\Domain\Enum\ReportSubscriptionType;

foreach ($cybersource->listReportDefinitions(ReportSubscriptionType::Custom) as $definition) {
    $definition->name;         // pass as CreateReportRequest::$definitionName
}

$definition = $cybersource->getReportDefinition(ReportDefinitionName::TransactionRequest, format: ReportFormat::Csv);

$definition->fieldNames();          // everything on offer — pass as CreateReportRequest::$fields
$definition->requiredFieldNames();  // columns the report always carries
$definition->supports(ReportFormat::Xml);
```

`ReportSubscriptionType` (`Custom`, `Standard`, `Classic`) selects which family a definition name
resolves against — asking under the wrong one is why a name that plainly exists comes back not
found.

Beyond the widget, the driver also charges a **native wallet token** directly. When you host
your own Apple Pay / Google Pay button — running the wallet's own `ApplePaySession`, merchant
validation, and (for Apple Pay) domain registration in your app — `chargeWallet` charges the
token via `processingInformation.paymentSolution` (`001` Apple Pay, `012` Google Pay). It takes a
`WalletToken` in either of CyberSource's two wallet shapes, and the SDK never decrypts the token
or handles the cleartext PAN itself:

- **`DecryptedWalletToken`** (canonical) — you decrypt the wallet payload in your app and pass the
  network-token fields (DPAN, cryptogram, expiry, optional ECI and card type); the driver sends
  them as `paymentInformation.tokenizedCard` with `transactionType` `1`.
- **`EncryptedWalletToken`** — you pass the raw device-encrypted token and the driver forwards it
  as `paymentInformation.fluidData` for CyberSource to decrypt (requires the wallet's
  payment-processing certificate registered with CyberSource's decryption service).

```php
use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\ValueObject\DecryptedWalletToken;
use Hyprpay\Payments\Domain\ValueObject\Money;

// Decrypt the Apple Pay PKPaymentToken in your app, then pass the network-token fields.
$result = $cybersource->chargeWallet(new WalletChargeRequest(
    token: new DecryptedWalletToken(
        number: $dpan,           // decrypted device PAN
        cryptogram: $cryptogram, // online payment cryptogram
        expiryMonth: '12',
        expiryYear: '2031',
        eci: '05',               // optional
        cardType: '001',         // optional CyberSource network code (001 Visa, 002 Mastercard, …)
    ),
    wallet: WalletType::ApplePay,
    money: Money::minor(2599, 'USD'),
    orderReference: 'ORDER-123',
));
// $result->status is Captured (or Authorized when capture: false).

// Or forward the raw encrypted token for CyberSource to decrypt:
// token: new EncryptedWalletToken($applePayPaymentDataJson)
```

For **PayTabs**, `paymentMethod` selects the integration type: `invoice` (an emailable
Invoice link), `managed` (an iframe-embeddable Managed Form), `paylink` (a reusable
PayLink), or the default Hosted Payment Page (pass `auth` for a hold to capture later).
To keep the payer on your own site instead of redirecting, either embed the Hosted Page
with `new PaytabsCheckoutOptions(iframe: true)` (optionally `framedReturnTop`,
`framedReturnParent`, `framedMessageTarget` — an HTTPS URL on your domain that
receives a `postMessage` when payment finishes so you can close the frame), or use
**Own Form**: collect the card in your own form, tokenise it in the browser with
PayTabs' client-side library, and charge the resulting `payment_token` via `charge()`
(the raw PAN never touches your server, keeping you in the light PCI tier):

```php
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;

$result = $gateway->charge(new ChargeRequest(
    transientToken: $paymentToken,          // browser-generated by PayTabs' JS
    money: Money::minor(9500, 'SAR'),
    orderReference: 'ORDER-127',
));

// 3-D Secure card → $result->status is Pending and $result->raw['redirect_url']
// holds the bank's 3DS page; a non-3DS card returns the final result inline.
```

Pass a
`PaytabsAgreement` on the options' `agreement` (description, `repeatAmount`, `repeatEvery`,
`firstInstallmentDueDate`, …) to start a Repeat Billing agreement — the customer completes
the initial payment and consents, then PayTabs auto-bills the schedule (recurring execution
and pause/cancel are managed PayTabs-side, not via the SDK). Pass a list of
`PaytabsSplitPayout` on `splitPayout` (each with its `itemTotal`, `mscFlag`, and
`PaytabsBeneficiary` details) to split the settled funds across beneficiaries after payment.

¹ PayTabs has no raw-PAN vault endpoint, so `vaultInstrument` is unsupported. Instead a
reusable card token is created by setting `PaytabsCheckoutOptions::$tokenise` (1–6, e.g. `2` = Hex32)
on any checkout — the token arrives in the callback/status `token` field — then charged
later with `chargeStoredCredential` (merchant-initiated → `recurring`, customer-initiated
→ `ecom`) and revoked with the driver's `deleteToken()`.

For **PayPal**, the driver speaks Orders v2 / Payments v2 and authenticates with OAuth 2.0
client credentials (client id → `merchant_id`, client secret → `shared_secret`), fetching a
bearer token once per request and reusing it across calls. `createCheckoutSession` creates
an order (intent `CAPTURE`, or `AUTHORIZE` when `paymentMethod: 'authorize'`) and returns
the buyer-approval redirect (`payer-action` link) plus the order id; `charge` then completes
that approved order — its `transientToken` is the order id, capturing when `capture` is true
and authorizing when false. Follow-ons act on the resulting payment resources: `capture`
and `void` take an authorization id, `refund` a capture id, and `getTransaction` reads an
order back by id. Cards are vaulted with `vaultInstrument` (PayPal's setup-token → payment-token
flow) and charged card-on-file via `chargeStoredCredential`, which stamps the network
stored-credential metadata (MIT → `RECURRING`, CIT → `ONE_TIME`). `verifyWebhook` posts the
notification's `PayPal-Transmission-*` headers to PayPal's verify-signature API using the
configured `webhook_id` (`PAYPAL_WEBHOOK_ID` → `webhook_secret`), so it makes one live call
rather than checking a local HMAC.

² For **PayPal**, `charge`'s `transientToken` is not a card token but the id of an order the
buyer has already approved on PayPal (returned by `createCheckoutSession`); calling `charge`
captures or authorizes that order server-to-server.

³ For **Mastercard MPGS**, `charge`'s `transientToken` is the id of a Hosted Session that
carries the card entered client-side; the driver PAYs it (or AUTHORIZEs when `capture: false`)
against the order id (`orderReference`) and transaction id (`idempotencyKey`) in the request URL.
`capture`, `refund`, `void`, and `reverseAuthorization` settle against that order (`void` /
`reverseAuthorization` target the prior transaction via `targetTransactionId`), and
`getTransaction` / `searchTransaction` retrieve the order — the reconciliation unit.

⁴ For **PayLink**, `vaultInstrument` stores a card in PayLink's CyberSource-TMS-backed
vault (returning a reusable token), `chargeStoredCredential` charges that token as an MIT/CIT
transaction — reusing the cardholder and billing captured at tokenize time, so no billing is
resent — and `deleteToken` revokes it.

⁵ **Tamara** is a redirect "buy now, pay later" flow with no immediate `charge`.
`createCheckoutSession` returns the hosted checkout URL; after the customer approves,
Tamara sends an `order_approved` webhook and you call the Tamara-specific
`authorise($orderId)` to move the order to `authorised` before `capture`. `void` and
`reverseAuthorization` both map to Tamara's single cancel operation (release an
authorised order before capture), and `refund` uses the simplified-refund endpoint after
capture. Every request is authenticated with the merchant Bearer token (`shared_secret`),
and `verifyWebhook` checks the shared `Authorization` header registered for the webhook
against `webhook_secret`.

