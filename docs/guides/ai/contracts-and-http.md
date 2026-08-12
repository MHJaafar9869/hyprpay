# Contracts, HTTP & Support (AI reference)

The remaining ports (`HttpClient`, `CredentialResolver`, `EventDispatcher`), the HTTP DTOs (`HttpRequest`/`HttpResponse`), the HttpClient decorator stack + test fake, the Events adapters, and the Support helpers (`Value`, `LogsAction`).

## Ports (`Hyprpay\Payments\Domain\Contract`)

### `HttpClient`

- `interface HttpClient` — outbound-HTTP port used by drivers to reach remote gateway APIs; decouples the SDK from any concrete transport (real `LaravelHttpClient` in prod, `FakeHttpClient` in tests).
- `send(HttpRequest $request): HttpResponse` — dispatch a request and return the response.

### `CredentialResolver`

- `interface CredentialResolver` — supplies gateway credentials for a given gateway, so the factory builds a driver without knowing where credentials come from. Default adapter: `ConfigCredentialResolver` (reads from app config).
- `resolve(GatewayName $gateway): GatewayCredentials` — resolve the credentials configured for the given gateway.

### `EventDispatcher`

- `interface EventDispatcher` — dispatches the SDK's payment domain events to the host, so the gateway decorator can announce what happened without knowing how the host handles it. Default adapter: `LaravelEventDispatcher`.
- `dispatch(PaymentEvent $event): void` — dispatch a payment domain event to registered listeners.

## HTTP DTOs (`Hyprpay\Payments\Domain\Http`)

### `HttpRequest`

- `final readonly class HttpRequest` — immutable description of an outbound HTTP request passed to the `HttpClient` port.
- Constructor:
  - `__construct(public string $method, public string $url, public array $headers = [], public ?string $body = null)` — `$method` = HTTP verb; `$url` fully-qualified; `$headers` is `array<string, string>`; `$body` raw or `null`.
- Methods:
  - `header(string $name): ?string` — return a request header value, or `null` when unset.
  - `hasBody(): bool` — whether the request carries a body (`$body !== null`).

### `HttpResponse`

- `final readonly class HttpResponse` — immutable result of an outbound gateway HTTP request; exposes status, raw body, headers, and JSON decoding.
- Constructor:
  - `__construct(public int $status, public string $body, public array $headers = [])` — `$headers` is `array<string, string|array<int, string>>` (value may be a single string or a list).
- Methods:
  - `ok(): bool` — 2xx (`>= 200 && < 300`).
  - `failed(): bool` — any non-2xx (`! ok()`).
  - `json(): array` — decode body as `array<string, mixed>`; returns `[]` when not valid JSON / not an array (via `Value::array`).

## HttpClient decorator stack (`Hyprpay\Payments\Infrastructure\Http`)

Composition order (top wraps bottom):

```
RetryingHttpClient → LoggingHttpClient → RateLimitingHttpClient → LaravelHttpClient
```

`RateLimitingHttpClient` sits BELOW `RetryingHttpClient` so every real attempt — including each retry — consumes a token. `FakeHttpClient` replaces the whole stack in tests.

### `LaravelHttpClient`

- `final readonly class LaravelHttpClient implements HttpClient` — adapter fulfilling `HttpClient` via Laravel's HTTP client. Translates `HttpRequest` into a call on Illuminate's HTTP factory and maps the result back.
- Constructor:
  - `__construct(private Factory $http, private int $timeout = 30)` — `$http` = `Illuminate\Http\Client\Factory`; `$timeout` in seconds, applied to every call.
- `send(HttpRequest $request): HttpResponse` — applies headers + timeout, attaches body with its content type when present, returns an `HttpResponse` (status, body, normalized headers).
- Private helpers: `normalizeHeaders(array $headers): array` (narrows Laravel's loose headers to `array<string, array<int,string>|string>`); `contentType(HttpRequest $request): string` (uses the request `Content-Type` header, else `application/json`).

### `LoggingHttpClient`

- `final readonly class LoggingHttpClient implements HttpClient` — decorator that logs request/response **metadata only** via a PSR-3 logger. Logs method, target URL, and response status; never logs headers/bodies (secrets, cards) and strips the URL query string (can carry a signature). Success at debug, failure at warning.
- Constructor:
  - `__construct(private HttpClient $inner, private LoggerInterface $logger)`
- `send(HttpRequest $request): HttpResponse` — logs `gateway.http.request` (debug), delegates, then logs `gateway.http.response` (debug) or `gateway.http.response.failed` (warning) with `{method, url, status}`.
- Private helper: `target(HttpRequest $request): string` — request URL without its query string.

### `RateLimitingHttpClient`

- `final class RateLimitingHttpClient implements HttpClient` — throttles outbound requests with a token bucket to stay under a gateway's rate limit. Bucket starts full (initial burst up to `maxRequests` passes without delay); refills at `maxRequests / perSeconds` per second; when empty, blocks just long enough for one token. Per-process (one bucket per instance), not distributed. Clock + sleeper are injectable for tests.
- Constructor:
  - `__construct(private readonly HttpClient $inner, int $maxRequests = 10, int $perSeconds = 1, ?Closure $clock = null, ?Closure $sleeper = null)` — `$maxRequests`/`$perSeconds` clamped to `>= 1`; `$clock` is `Closure(): float` (monotonic seconds, defaults to `hrtime()`); `$sleeper` is `Closure(int): void` (microseconds, defaults to `usleep()`).
- `send(HttpRequest $request): HttpResponse` — refills, waits for a token if the bucket is below 1, consumes one token, then delegates.
- Private helper: `refill(): void` — adds tokens accrued since last tick, capped at capacity.

### `RetryingHttpClient`

- `final readonly class RetryingHttpClient implements HttpClient` — retries transient failures with exponential backoff. Re-sends when the inner client returns a retryable status or throws a configured retryable exception. Safe to replay writes (deterministic requests + idempotency keys → gateway deduplicates). Backoff = `baseDelayMs * 2^(n-1)`; sleeper injectable.
- Constructor:
  - `__construct(private HttpClient $inner, private int $maxAttempts = 3, private int $baseDelayMs = 200, private array $retryableStatuses = [408, 429, 500, 502, 503, 504], private array $retryableExceptions = [], ?Closure $sleeper = null)` — `$maxAttempts` counts the first try; `$retryableExceptions` is `array<int, class-string<Throwable>>`; `$sleeper` is `Closure(int): void` (microseconds, defaults to `usleep()`).
- `send(HttpRequest $request): HttpResponse` — loops: on throw, rethrows if attempts exhausted or exception not retryable, else backs off and retries; on response, returns if attempts exhausted or status not retryable, else backs off and retries.
- Private helpers: `isRetryableStatus(int $status): bool`; `shouldRetryException(Throwable $exception): bool`; `backoff(int $attempt): void`.

### `FakeHttpClient` (test double)

- `final class FakeHttpClient implements HttpClient` — in-memory test double. Records every request and returns queued responses in order (falling back to a default), so tests can assert outbound calls and stub replies without real HTTP.
- Constructor:
  - `__construct(?HttpResponse $default = null)` — `$default` returned once the queue is exhausted; defaults to `new HttpResponse(200, '{}')`.
- Methods:
  - `queue(HttpResponse $response): self` — enqueue a response (fluent).
  - `queueJson(array $json, int $status = 200): self` — enqueue a JSON-encoded response (`$json` is `array<string, mixed>`).
  - `queueBody(string $body, int $status = 200): self` — enqueue a raw-body response.
  - `send(HttpRequest $request): HttpResponse` — record the request; return next queued response or the default.
  - `recorded(): array` — every captured request, in order (`array<int, HttpRequest>`).
  - `lastRequest(): ?HttpRequest` — most recent request, or `null`.
  - `requestCount(): int` — number of requests sent.

## Events adapters (`Hyprpay\Payments\Infrastructure\Events`)

### `LaravelEventDispatcher`

- `final readonly class LaravelEventDispatcher implements EventDispatcher` — forwards payment events to Laravel's dispatcher as-is, so listeners can subscribe to a concrete event class or the `PaymentEvent` interface.
- Constructor:
  - `__construct(private Dispatcher $events)` — `$events` = `Illuminate\Contracts\Events\Dispatcher`.
- `dispatch(PaymentEvent $event): void` — forwards to `$this->events->dispatch($event)`.

### `LoggingPaymentEventListener`

- `final readonly class LoggingPaymentEventListener` — listener that writes a redaction-safe audit line for every payment event via PSR-3. Subscribes to the `PaymentEvent` interface (one registration covers all events). Logs metadata only — event name, gateway, correlation ids, normalized status/outcome; never the result's `raw` payload or card data.
- Constructor:
  - `__construct(private LoggerInterface $logger)`
- Methods:
  - `handle(PaymentEvent $event): void` — logs `gateway.payment.event` (info) with `{event: class_basename, gateway: gateway()->value, ...per-event context}`.
  - Private `context(PaymentEvent $event): array` — per-event safe fields (order/transaction/authorization/instrument/customer ids, status, success, webhook verified/type). `array<string, mixed>`.
  - Private `outcome(PaymentResult $result): array` — `{transaction, status, success}` from a `PaymentResult`.

### `RecordingEventDispatcher` (test double)

- `final class RecordingEventDispatcher implements EventDispatcher` — in-memory test double recording every dispatched event so tests can assert which fired and with what payload.
- Methods:
  - `dispatch(PaymentEvent $event): void` — append the event.
  - `dispatched(): array` — every dispatched event, in order (`array<int, PaymentEvent>`).
  - `last(): ?PaymentEvent` — most recent event, or `null`.
  - `count(): int` — number dispatched.

## Support helpers (`Hyprpay\Payments\Infrastructure\Support`)

### `Value`

- `final class Value` — typed coercion helpers for values decoded from gateway JSON (`mixed` → concrete type) at a single point of use, keeping drivers static-analysis clean. All methods static.
  - `static string($value, string $default = ''): string` — coerce to string; `$default` when not scalar.
  - `static nullableString($value): ?string` — string, or `null` when blank/non-scalar (mirrors `filled($v) ? (string)$v : null`).
  - `static int($value, int $default = 0): int` — coerce to int; `$default` when not numeric.
  - `static bool($value): bool` — coerce to boolean (`(bool) $value`).
  - `static array($value): array` — narrow to `array<string, mixed>`; `[]` when not an array.

### `Concerns\LogsAction` (trait)

- `trait LogsAction` — adds level-based, self-identifying logging via an injected PSR-3 logger. Each message is prefixed with the class short name (`[ClassName] message`); each context array is tagged with the FQ class under `action` and recursively masked so sensitive keys never reach the log. Framework-agnostic — the consuming class supplies the destination via `logger()`.
- Required:
  - `abstract protected logger(): LoggerInterface` — the PSR-3 logger to write to (implemented by the consuming class).
- Level methods (each `(string $message, array $context = []): void`, `$context` is `array<string, mixed>`):
  - `protected logDebug`, `logInfo`, `logWarning`, `logError`, `logCritical`, `logAlert`.
- Timing:
  - `protected logTimedAction(string $message, callable $callback, array $context = []): mixed` — run `$callback` (`callable(): T`), then log `$message` with `duration_ms`; logs whether the callback returns or throws (on throw, still logs then propagates). Returns `T`.
- Overridable hooks:
  - `protected logName(): string` — the label the message is prefixed with (class short name by default).
  - `protected baseLogContext(): array` — base context merged into every line (`['action' => static::class]` by default; override or return `[]` to drop it).
- Private internals: `executeLogging(string $level, string $message, array $context): void`; `buildLogMessage(string $message): string` (`"[{name}] {message}"`); `buildLogContext(array $context): array` (merge base + mask); `maskSensitiveData(array $data): array` — recursively replaces values of sensitive keys with `********`. Sensitive keys: `password, password_confirmation, cvv, card_number, pan, token, secret, key, api_key, shared_secret, authorization` (case-insensitive).
