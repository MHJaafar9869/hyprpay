# AI & MCP docs

A machine-consumable, **100%-coverage** reference for `hyprpay/payments`, written for AI coding
assistants and agents — every class, operation, request/result DTO, value object, enum, exception,
event, and config key in the package, plus a guide for exposing the SDK through MCP.

If you are an AI assistant helping someone use this SDK, load these files as context. They are
terse and structured on purpose; nothing in `src/` is left undocumented (see `class-index.md`).

## The SDK in one paragraph

`hyprpay/payments` is a self-contained, multi-gateway payment SDK for PHP. Eight drivers —
**CyberSource Unified Checkout, Fawry, Paymob, PayLink, PayTabs, PayPal, Mastercard MPGS, and
Authorize.Net** — sit behind one `PaymentGatewayInterface`. You resolve a driver from
`PaymentGatewayFactory::make(GatewayName::X)` and call operations that each take a request DTO and
return a result DTO. Money is carried as integer minor units (`Money::minor(10000, 'USD')`).
Operations a gateway does not support throw `UnsupportedOperationException`. The core is
framework-agnostic (ports for HTTP and credentials); Laravel adapters and auto-discovery ship in the
Infrastructure layer.

```php
use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;

$gateway = $factory->make(GatewayName::AuthorizeNet);          // $factory: PaymentGatewayFactory (DI)
$result  = $gateway->charge(new ChargeRequest(
    transientToken: $tokenFromWidget,
    money: Money::minor(5000, 'USD'),                          // 50.00 USD
    orderReference: 'ORDER-129',                              // also the default idempotency key
));
// $result->success, $result->status (PaymentStatus), $result->transactionId, $result->raw
```

## Map of these docs

| File | Covers |
|---|---|
| **[overview.md](overview.md)** | Orientation: architecture (3 DDD layers), install, resolving a gateway, and core conventions (exact money, idempotency, ports, events, logging, HTTP stack, testing). |
| **[payment-gateway-interface.md](payment-gateway-interface.md)** | The full `PaymentGatewayInterface` — every operation, request→result, and the "unsupported operations throw" contract; `AbstractPaymentGateway` defaults. |
| **[requests.md](requests.md)** | Every Command (request) DTO and the `CheckoutOptions` interface — full field-by-field. |
| **[results.md](results.md)** | Every Result DTO and its fields/helpers. |
| **[value-objects.md](value-objects.md)** | `Money`, `Customer`, `BillingAddress`, `GatewayCredentials`, `BrowserDeviceData`. |
| **[enums.md](enums.md)** | `GatewayName`, `PaymentStatus`, `CredentialInitiator`, `MandateCompletionType` — every case. |
| **[exceptions.md](exceptions.md)** | The exception hierarchy and when each is thrown. |
| **[events.md](events.md)** | `PaymentEvent` and every domain event, its fields, and when it fires. |
| **[contracts-and-http.md](contracts-and-http.md)** | The ports (`HttpClient`, `CredentialResolver`, `EventDispatcher`), the HTTP DTOs and decorator stack, and support helpers. |
| **[gateways.md](gateways.md)** | All eight gateways in depth — signing, endpoints, supported operations, and each gateway's typed `CheckoutOptions` and enums. |
| **[application-console-config.md](application-console-config.md)** | `PaymentGatewayFactory`, `TransactionReconciler`, driver decorators, the reconcile Artisan commands, and a complete config-key reference. |
| **[mcp-server.md](mcp-server.md)** | Exposing the SDK's operations as MCP tools for an AI agent — tool schemas, a PHP handler, and the safety guardrails money-moving tools require. |
| **[class-index.md](class-index.md)** | The exhaustive index — all 186 classes/interfaces/enums/traits with a one-line purpose each. Start here to confirm nothing is missing. |

## For humans

These files live under `docs/guides/ai/` and are plain Markdown. The human-facing guides are one
level up: [architecture](../architecture.md), [gateways](../gateways.md),
[operations](../operations.md), and [observability](../observability.md).
