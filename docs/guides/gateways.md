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

## Gateways & operations

Every driver implements the same `PaymentGatewayInterface`. Operations a gateway does
not support throw `UnsupportedOperationException`, so you can rely on the same surface
everywhere.

| Operation | CyberSource UC | Fawry | Paymob | PayLink | PayTabs | PayPal | Mastercard MPGS |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `createCheckoutSession` | ✅ capture context | ✅ hosted / card / wallet / pay-at-Fawry / MyFawry / instalment | ✅ iframe flow | ✅ invoice link / iframe | ✅ hosted / invoice / paylink / managed | ✅ order → approval redirect | ✅ hosted checkout session |
| `charge` (transient token) | ✅ | — | — | — | ✅ Own Form (payment token) | ✅ complete approved order² | ✅ session (PAY / AUTHORIZE)³ |
| `capture` | ✅ | ✅ (Auth/Capture) | ✅ | ✅ (settle) | ✅ | ✅ | ✅ |
| `refund` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `void` | ✅ | ✅ (cancel auth) | ✅ | ✅ | ✅ | ✅ | ✅ |
| `reverseAuthorization` | ✅ | — | — | ✅ | ✅ (release) | — | ✅ (void of auth) |
| `enrollPayerAuth` / `validatePayerAuth` (3-DS) | ✅ | — | — | — | — | — | ✅ |
| `vaultInstrument` / `chargeStoredCredential` | ✅ (TMS, MIT/CIT) | — | — | ✅ vault + revoke⁴ | ✅ token (MIT/CIT)¹ | ✅ vault (MIT/CIT) | ✅ token (MIT/CIT) |
| `requestDccRate` (Dynamic Currency Conversion) | ✅ | — | — | — | — | — | — |
| `getTransaction` / `searchTransaction` | ✅ | ✅ | ✅ | ✅ | ✅ query | ✅ order lookup | ✅ order lookup |
| `verifyWebhook` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ API | ✅ notification secret |

Provider-specific inputs (e.g. Fawry payment method, Paymob integration/iframe ids,
card or wallet details) are passed through `CheckoutSessionRequest::$options` and the
`GatewayCredentials::$extra` bag. `$options` is a typed, per-gateway options DTO
implementing `CheckoutOptions` — `FawryCheckoutOptions`, `PaymobCheckoutOptions`,
`PaylinkCheckoutOptions`, `PaytabsCheckoutOptions`, and `PayPalCheckoutOptions` — so
every field is named and type-checked (including enums like `PayPalUserAction` and
nested value objects like `PaytabsAgreement` / `PaytabsSplitPayout` / `PaytabsLineItem`
and `FawryCard`) rather than stringly-typed. Each DTO's static `fromArray()` builds one
from a raw config array when you need to; each driver narrows `$options` to its own type.

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
vault (returning a reusable token) and `deleteToken` revokes it. Stored-credential charges
(`chargeStoredCredential`) aren't wired yet — PayLink's token-charge endpoint requires the
cardholder name and full billing address, which `StoredCredentialChargeRequest` does not carry.

