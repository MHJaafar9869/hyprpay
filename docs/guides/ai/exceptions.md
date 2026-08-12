# Exceptions (AI reference)

Every exception the SDK raises, its place in the hierarchy, when it is thrown, and its static factory methods. All live in `Hyprpay\Payments\Domain\Exception`.

## Hierarchy

```
RuntimeException
└── GatewayException                      (base; catch the whole family)
    ├── GatewayNotSupportedException      (final)
    ├── GatewayRequestException           (final)
    ├── MissingCredentialsException       (final)
    ├── PaymentVerificationException      (final)
    ├── UnsupportedOperationException     (final)
    └── WebhookVerificationException      (final)
```

Catch `GatewayException` to handle all SDK gateway errors in one place.

## `GatewayException`

- `class GatewayException extends RuntimeException` — base for all gateway errors. No factories; no extra fields.

## `GatewayNotSupportedException`

- `final class GatewayNotSupportedException extends GatewayException`
- Thrown when a gateway is requested by name but no driver is registered for it (unknown/misspelled identifier string).
- Factory:
  - `static forName(string $name): self` — `$name` = the unknown gateway name. Message: `"No payment gateway driver is registered for '{name}'."`

## `GatewayRequestException`

- `final class GatewayRequestException extends GatewayException`
- Thrown when an HTTP call to the gateway API returns an error (non-2xx) response. Carries the status, raw body, and decoded payload so callers can inspect or retry.
- Constructor:
  - `__construct(public readonly int $status, public readonly string $responseBody, public readonly array $response = [], string $message = '')` — `$response` is `array<string, mixed>` (decoded JSON). When `$message` is empty, defaults to `"Gateway request failed with HTTP {status}."`
- Public readonly fields: `int $status`, `string $responseBody`, `array $response`.
- Factory:
  - `static fromResponse(HttpResponse $response, string $context = ''): self` — builds from a failed gateway HTTP response. Decodes the JSON body, extracts a reason via `reason` → `message` → `status` keys, prefixes with `$context` (operation label). Message form: `"{context}: CyberSource returned HTTP {status} ({reason})."`
- Methods:
  - `isRetryable(): bool` — true for transient statuses `408, 429, 502, 503, 504`.
- Private helper: `static extractReason(array $decoded): ?string` — returns first string among `reason`/`message`/`status`, else `null`.

## `MissingCredentialsException`

- `final class MissingCredentialsException extends GatewayException`
- Thrown when a gateway's required credentials are absent or incomplete (raised by the credential resolver when config is missing or lacks required fields).
- Factory:
  - `static forGateway(GatewayName $gateway): self` — Message: `"Missing or incomplete credentials for the {gateway->label()} gateway."`

## `PaymentVerificationException`

- `final class PaymentVerificationException extends GatewayException`
- Thrown when a client-supplied orchestrated-payment result JWT cannot be trusted (Unified Checkout v1 autoProcessing / completeMandate). The signed result JWT must verify against the public key from the capture context and match the order it was minted for. Any failure surfaces here so the result is rejected rather than trusted.
- Factories (each takes reason/expected/actual strings and returns `self`):
  - `static missingVerificationKey(string $reason): self` — capture context carries no usable verification key. Message: `"Result JWT verification key could not be sourced from the capture context: {reason}."`
  - `static invalidSignature(string $reason): self` — signature invalid/untrusted. Message: `"Result JWT signature verification failed: {reason}."`
  - `static expired(string $reason): self` — token expired or not yet valid. Message: `"Result JWT has expired or is not yet valid: {reason}."`
  - `static issuerMismatch(string $expected, string $actual): self` — issued by an unexpected issuer. Message: `"Result JWT issuer mismatch: expected '{expected}', got '{actual}'."`
  - `static orderReferenceMismatch(string $expected, string $actual): self` — order reference does not match the request. Message: `"Result JWT order reference mismatch: expected '{expected}', got '{actual}'."`
  - `static currencyMismatch(string $expected, string $actual): self` — currency does not match. Message: `"Result JWT currency mismatch: expected '{expected}', got '{actual}'."`
  - `static amountMismatch(string $expected, string $actual): self` — amount does not match. Message: `"Result JWT amount mismatch: expected '{expected}', got '{actual}'."`

## `UnsupportedOperationException`

- `final class UnsupportedOperationException extends GatewayException`
- Thrown when a gateway driver does not implement a requested operation — raised by `AbstractPaymentGateway`'s default method stubs when a concrete driver has not overridden it (e.g. `refund`, `void`). See `payment-gateway-interface.md`.
- Factory:
  - `static forOperation(GatewayName $gateway, string $operation): self` — `$operation` = the method name invoked. Message: `"The {gateway->label()} gateway does not support the '{operation}' operation."`

## `WebhookVerificationException`

- `final class WebhookVerificationException extends GatewayException`
- Thrown when an inbound webhook fails signature/authenticity verification — the payload must be rejected, not processed.
- Factory:
  - `static invalidSignature(string $reason = 'signature mismatch'): self` — Message: `"Webhook signature verification failed: {reason}."`
