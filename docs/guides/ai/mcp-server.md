# MCP integration — exposing the SDK as agent tools

How to expose `hyprpay/payments` operations to an AI agent through a [Model Context
Protocol](https://modelcontextprotocol.io) (MCP) server: the tool schemas to declare, a PHP
handler that maps each tool call onto the SDK, and — because these tools move money — the
guardrails that are **not optional**.

> The SDK ships no MCP server. This guide shows how to wire its operations into an MCP server
> of your choice (e.g. `php-mcp/server`, `logiscape/mcp-sdk-php`, or a thin HTTP shim). The SDK
> side is just `PaymentGatewayFactory::make()` + a request DTO, exactly as in normal use.

## Read this first — payment tools are high-risk

An AI agent calling a `charge` or `refund` tool moves real money and cannot be undone by the
model. Treat money-moving tools as privileged actions:

- **Human-in-the-loop for money movement.** `charge`, `capture`, `refund`, `void`,
  `chargeStoredCredential`, `reverseAuthorization` should require explicit human confirmation
  before execution — never fully autonomous. Read-only tools (`getTransaction`,
  `searchTransaction`, `verifyWebhook`, `reconcile`, `requestDccRate`) are safe to auto-run.
- **Sandbox by default.** Resolve credentials with `test_mode` on unless a human has explicitly
  authorized live mode. The SDK selects the sandbox host from `test_mode` (see the config).
- **Never route raw card data (PAN/CVV) through a tool.** Cards are tokenised in the browser
  (CyberSource capture context / Accept.js opaque data / MPGS session / PayTabs Own Form). Tools
  only ever receive the resulting **transient token**, never the card.
- **Credentials stay server-side.** Keys come from config or your `CredentialResolver` — never
  from tool arguments. The agent picks a `gateway`, not a secret.
- **Amounts are integer minor units.** Take `amount_minor` (int) + `currency` (ISO-4217) so a
  float can never round `10.00` into `9.999999`. Map to `Money::minor($amountMinor, $currency)`.
- **Validate + constrain.** Enforce per-tool allowlists, per-agent spend caps, idempotency keys,
  and rate limits. Turn on the SDK's audit trail (`gateway.events.log`) and operation logging
  (`gateway.logging.operations`) so every tool-driven call is recorded.
- **Idempotency.** Pass a stable `idempotency_key`/`order_reference`; a retried tool call then
  deduplicates at the gateway instead of double-charging.

## Tool catalogue

Declare one MCP tool per operation you want to expose. Each `inputSchema` is JSON Schema. The
`gateway` enum uses the `GatewayName` backing values:
`cybersource_uc`, `fawry`, `paymob`, `paylink`, `paytabs`, `paypal`, `mpgs`, `authorize_net`.

### `payments_charge` — charge a transient token _(money-moving → confirm)_

```json
{
  "name": "payments_charge",
  "description": "Charge a browser-tokenised card (transient token) through a gateway. Moves money — requires human confirmation.",
  "inputSchema": {
    "type": "object",
    "properties": {
      "gateway": { "type": "string", "enum": ["cybersource_uc","paytabs","paypal","mpgs","authorize_net"] },
      "transient_token": { "type": "string", "description": "Token/opaque-data value from the checkout widget. Never a raw PAN." },
      "amount_minor": { "type": "integer", "minimum": 1, "description": "Amount in minor units (e.g. 10000 = 100.00)." },
      "currency": { "type": "string", "pattern": "^[A-Z]{3}$" },
      "order_reference": { "type": "string", "description": "Your order id; also the default idempotency key." },
      "capture": { "type": "boolean", "default": true, "description": "false = authorize only (hold)." }
    },
    "required": ["gateway", "transient_token", "amount_minor", "currency"]
  }
}
```

### `payments_capture` — settle an authorization _(money-moving → confirm)_

`{ gateway, transaction_id, amount_minor, currency, idempotency_key? }` → maps to `CaptureRequest`.

### `payments_refund` — refund a settled charge _(money-moving → confirm)_

`{ gateway, transaction_id, amount_minor, currency, reason?, idempotency_key? }` → `RefundRequest`.

### `payments_void` — cancel an uncaptured transaction _(money-moving → confirm)_

`{ gateway, transaction_id, idempotency_key? }` → `VoidRequest`.

### `payments_get_transaction` — authoritative status _(read-only → auto-ok)_

`{ gateway, transaction_id }` → `getTransaction(): TransactionSnapshot`.

### `payments_reconcile` — batch status lookup _(read-only → auto-ok)_

`{ gateway, transaction_ids: string[] }` → `TransactionReconciler::reconcile()`; each id returns a
`ReconciliationOutcome` (a failed lookup is captured, not thrown).

### `payments_verify_webhook` — verify an inbound callback _(read-only → auto-ok)_

`{ gateway, raw_body, headers }` → `verifyWebhook(): WebhookEvent`. Always verify before trusting a
webhook; the SDK returns `verified: false` rather than throwing on a bad signature.

### `payments_create_checkout_session` — start a hosted/widget flow _(setup → usually safe)_

`{ gateway, amount_minor, currency, order_reference?, return_url?, payment_method? }` →
`createCheckoutSession(): CheckoutSession` (returns a `jwt` / `redirectUrl` / `reference` depending on
the gateway). Not supported by `authorize_net` — it uses the direct Accept.js `charge`.

> For 3-D Secure (`enrollPayerAuth` / `validatePayerAuth`), DCC (`requestDccRate`), vaulting
> (`vaultInstrument` / `chargeStoredCredential`), and the CyberSource orchestrated confirm
> (`confirmOrchestratedPayment`), add tools the same way — map arguments onto the matching request
> DTO. See `requests.md` for every DTO and `payment-gateway-interface.md` for the operation set.

## PHP handler

A single dispatcher resolves the gateway and maps the tool arguments onto the request DTO. Wire
`dispatch()` into your MCP server's tool-call callback. `$confirmed` is your human-confirmation gate
for money-moving tools — enforce it **before** the SDK call.

```php
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Application\TransactionReconciler;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;

final readonly class PaymentToolHandler
{
    /** @param list<string> $moneyMoving Tools that require human confirmation. */
    public function __construct(
        private PaymentGatewayFactory $factory,
        private TransactionReconciler $reconciler,
        private array $moneyMoving = [
            'payments_charge', 'payments_capture', 'payments_refund',
            'payments_void', 'payments_create_checkout_session',
        ],
    ) {}

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>  JSON-serialisable result for the MCP tool response.
     */
    public function dispatch(string $tool, array $args, bool $confirmed): array
    {
        if (in_array($tool, $this->moneyMoving, true) && ! $confirmed) {
            return ['error' => 'confirmation_required', 'tool' => $tool];
        }

        $gateway = $this->factory->make(GatewayName::from((string) $args['gateway']));

        return match ($tool) {
            'payments_charge' => (array) $gateway->charge(new ChargeRequest(
                transientToken: (string) $args['transient_token'],
                money: Money::minor((int) $args['amount_minor'], (string) $args['currency']),
                capture: (bool) ($args['capture'] ?? true),
                orderReference: $args['order_reference'] ?? null,
            )),
            'payments_capture' => (array) $gateway->capture(new CaptureRequest(
                transactionId: (string) $args['transaction_id'],
                money: Money::minor((int) $args['amount_minor'], (string) $args['currency']),
                idempotencyKey: $args['idempotency_key'] ?? null,
            )),
            'payments_refund' => (array) $gateway->refund(new RefundRequest(
                transactionId: (string) $args['transaction_id'],
                money: Money::minor((int) $args['amount_minor'], (string) $args['currency']),
                reason: $args['reason'] ?? null,
                idempotencyKey: $args['idempotency_key'] ?? null,
            )),
            'payments_void' => (array) $gateway->void(new VoidRequest(
                transactionId: (string) $args['transaction_id'],
                idempotencyKey: $args['idempotency_key'] ?? null,
            )),
            'payments_get_transaction' => (array) $gateway->getTransaction((string) $args['transaction_id']),
            'payments_verify_webhook' => (array) $gateway->verifyWebhook(
                (string) $args['raw_body'],
                (array) $args['headers'],
            ),
            'payments_reconcile' => array_map(
                static fn ($o): array => (array) $o,
                $this->reconciler->reconcile(
                    GatewayName::from((string) $args['gateway']),
                    (array) $args['transaction_ids'],
                ),
            ),
            default => ['error' => 'unknown_tool', 'tool' => $tool],
        };
    }
}
```

The returned Result DTOs (`PaymentResult`, `RefundResult`, `TransactionSnapshot`, `WebhookEvent`, …)
are `readonly` value objects; expose their public fields (`success`, `status`, `transactionId`,
`message`, `raw`, …) as the tool result. Casting a DTO to `array` gives its public properties; for a
stable schema, map fields explicitly instead. An `UnsupportedOperationException` means the chosen
gateway does not implement that operation — surface it as a tool error, not a crash.

## What to expose vs. withhold

| Expose (read-only, auto) | Gate behind human confirmation | Prefer NOT to expose to agents |
|---|---|---|
| `getTransaction`, `searchTransaction` | `charge`, `capture`, `refund`, `void` | anything accepting raw card data |
| `reconcile` | `chargeStoredCredential`, `reverseAuthorization` | credential/secret management |
| `verifyWebhook`, `requestDccRate` | `createCheckoutSession`, `vaultInstrument` | live-mode toggling |

See `class-index.md` for the full package surface, `payment-gateway-interface.md` for the operation
contract, and `gateways.md` for which gateway supports which operation.
