# hyprpay/payments — Application, Console & Config

Reference for the Application layer, driver decorators, credential resolver, `GatewayServiceProvider` wiring, the reconcile Artisan commands, and every key in `config/gateway.php`. Companion to [overview.md](./overview.md).

## Application layer

### `PaymentGatewayFactory`
`src/Application/PaymentGatewayFactory.php` — `final readonly`. Composition-edge factory; container singleton.

Constructor: `__construct(HttpClient $http, CredentialResolver $credentialResolver, ?EventDispatcher $events = null, ?LoggerInterface $logger = null)`. The `$events` and `$logger` args decide whether returned drivers are decorated.

- `make(GatewayName $gateway, ?GatewayCredentials $credentials = null): PaymentGatewayInterface`
  1. Credentials = the passed `$credentials`, else `$credentialResolver->resolve($gateway)`.
  2. Constructs the concrete driver via `match ($gateway)` (one branch per `GatewayName` case), passing `($resolved, $this->http)`.
  3. **If `$events` is an `EventDispatcher`** → wrap in `EventDispatchingGateway($driver, $events)`.
  4. **If `$logger` is a `LoggerInterface`** → wrap in `LoggingGateway($driver, $logger)` and return.
  5. With neither, returns the bare driver.
  Wrap order: logging is outermost, so it times the event dispatch too.
- `makeByName(string $name, ?GatewayCredentials $credentials = null): PaymentGatewayInterface` — `GatewayName::tryFrom($name)`; on `null` throws `GatewayNotSupportedException::forName($name)`; else delegates to `make()`.

### `TransactionReconciler`
`src/Application/TransactionReconciler.php` — `final readonly`. Constructor takes the `PaymentGatewayFactory`.

- `reconcile(GatewayName $gateway, array $transactionIds, ?GatewayCredentials $credentials = null): array` — makes the driver once, then loops `$transactionIds` calling `$driver->getTransaction($id)`. Each id yields a `ReconciliationOutcome`; a **`GatewayException`** for one id is captured as an error outcome (message stored) rather than aborting the batch. Non-`GatewayException` errors propagate. Returns one outcome per id, in order.

### `ReconciliationOutcome`
`src/Application/ReconciliationOutcome.php` — `final readonly`. Result of reconciling a single id.

- Props: `public string $transactionId`, `public ?TransactionSnapshot $snapshot = null`, `public ?string $error = null`.
- `reconciled(): bool` — true when `$snapshot instanceof TransactionSnapshot`.

## Driver decorators (`src/Infrastructure/Gateway/`)

Both implement `PaymentGatewayInterface`, `final readonly`, wrap an inner driver, and pass `name()`/`credentials()` straight through.

### `EventDispatchingGateway`
`EventDispatchingGateway.php`. Constructor: `(PaymentGatewayInterface $inner, EventDispatcher $events)`.

After the inner driver returns, dispatches the matching `PaymentEvent`:

| Operation | Event dispatched |
|---|---|
| `createCheckoutSession` | `CheckoutSessionCreated` |
| `charge` | `PaymentCharged` |
| `capture` | `PaymentCaptured` |
| `refund` | `PaymentRefunded` |
| `void` | `PaymentVoided` |
| `reverseAuthorization` | `AuthorizationReversed` |
| `vaultInstrument` | `InstrumentVaulted` |
| `chargeStoredCredential` | `StoredCredentialCharged` |
| `verifyWebhook` | `WebhookReceived` |

Events fire on **completion regardless of success** (the result's status carries the outcome, so listeners see declines). If the inner call throws, the exception propagates and **no** event is dispatched. Pass-through / no event: `requestDccRate`, `enrollPayerAuth`, `validatePayerAuth`, `getTransaction`, `searchTransaction`.

### `LoggingGateway`
`LoggingGateway.php`. Constructor: `(PaymentGatewayInterface $inner, LoggerInterface $logger, array $extraContext = [])`.

Runs every operation inside `LogsAction::logTimedAction()` (trait `Infrastructure\Support\Concerns\LogsAction`), producing a `[LoggingGateway] {op}` line with `duration_ms` and a per-op context. `logName()` = the inner gateway's key; `baseLogContext()` = `$extraContext`. Every op's context is tagged `gateway` and carries safe fields only (order/transaction reference, `amount` via `Money::toDecimalString()`, `currency`, etc. — never PAN/CVV/tokens; the trait masks sensitive keys as a backstop). `verifyWebhook` logs with empty extra context. Logged ops: all lifecycle ops **plus** `requestDccRate`, `enrollPayerAuth`, `validatePayerAuth`, `getTransaction`, `searchTransaction`, `verifyWebhook` (unlike the event decorator, logging covers reads too).

## `ConfigCredentialResolver`
`src/Infrastructure/Credentials/ConfigCredentialResolver.php` — `final readonly`, implements `CredentialResolver`. Constructor takes Laravel's config `Repository`.

- `resolve(GatewayName $gateway): GatewayCredentials` — reads `gateway.gateways.{$gateway->value}`. If it is not a non-blank array → `MissingCredentialsException::forGateway($gateway)`. Builds `GatewayCredentials::fromConfig(...)`; if `!$credentials->isComplete()` (empty `shared_secret`) → same exception. Otherwise returns the DTO. Used as the factory's fallback when no explicit credentials are passed.

## `GatewayServiceProvider`
`src/Infrastructure/GatewayServiceProvider.php` — `final`, extends Laravel `ServiceProvider`.

**`register()`:**
- `mergeConfigFrom(config/gateway.php, 'gateway')`.
- `registerPackageLogChannel()` — unless the host already defines `logging.channels.hyprpay`, sets it to a `daily` channel at `storage/logs/hyprpay.log`, `days` = `gateway.logging.days` (14), `level` = `gateway.logging.level` (`debug`).
- Binds **`HttpClient`** to the decorator stack (built outer-in as returned): `RetryingHttpClient` (attempts = `gateway.http.retries` + 1, base delay = `gateway.http.retry_base_delay_ms`, retryable `ConnectionException`) wrapping — conditionally — `LoggingHttpClient` (if `gateway.http.logging`) wrapping — conditionally — `RateLimitingHttpClient` (if `gateway.http.rate_limit`, args `rate_limit_max_requests`, `rate_limit_per_seconds`) wrapping `LaravelHttpClient` (timeout = `gateway.http.timeout`).
- Binds **`CredentialResolver`** → `ConfigCredentialResolver`.
- Binds **`EventDispatcher`** → `LaravelEventDispatcher`.
- Registers **`PaymentGatewayFactory`** as a **singleton**: passes the `EventDispatcher` only when `gateway.events.enabled` (default true), and the package logger only when `gateway.logging.operations`.
- Contextual binding: `LoggingPaymentEventListener` gets the package logger for its `LoggerInterface`.

**`boot()`:**
- If `gateway.events.log`, attaches `LoggingPaymentEventListener` as a listener on the `PaymentEvent` interface.
- **Console only:** publishes `config/gateway.php` → `config/gateway.php` under tag **`gateway-config`**; and, if `gateway.commands.reconcile` (default true), registers the 8 reconcile commands.

Package logger (`packageLogger()`): the channel named by `gateway.logging.channel`, else the dedicated `hyprpay` channel.

## Reconcile Artisan commands
`src/Infrastructure/Console/`. Base `ReconcileCommand` (abstract) derives signature `gateway:reconcile:{key} {transaction* : one or more ids}` and the description from its bound `GatewayName`. `handle()` runs `TransactionReconciler->reconcile()`, renders a table (Transaction / Status / Amount / Order Ref; failed lookups show `lookup failed` + the error), and **exits non-zero if any id failed to reconcile** — safe for monitoring/scheduling. Each subclass only overrides `gateway()`.

| Command | Class | `GatewayName` |
|---|---|---|
| `gateway:reconcile:cybersource_uc` | `ReconcileCybersourceCommand` | `CybersourceUnifiedCheckout` |
| `gateway:reconcile:fawry` | `ReconcileFawryCommand` | `Fawry` |
| `gateway:reconcile:paymob` | `ReconcilePaymobCommand` | `Paymob` |
| `gateway:reconcile:paylink` | `ReconcilePaylinkCommand` | `Paylink` |
| `gateway:reconcile:paytabs` | `ReconcilePaytabsCommand` | `Paytabs` |
| `gateway:reconcile:paypal` | `ReconcilePayPalCommand` | `PayPal` |
| `gateway:reconcile:mpgs` | `ReconcileMpgsCommand` | `Mpgs` |
| `gateway:reconcile:authorize_net` | `ReconcileAuthorizeNetCommand` | `AuthorizeNet` |

Example: `php artisan gateway:reconcile:mpgs TX-1 TX-2`.

## Config reference — `config/gateway.php`

All `env()` reads. Every key below.

### Top level
| Key | Env var | Default | Meaning |
|---|---|---|---|
| `default` | `GATEWAY_DEFAULT` | `cybersource_uc` | Default gateway when the factory is asked without an explicit `GatewayName`. Must be a `GatewayName` backing value. |

### `http.*` — HTTP transport (all gateways)
| Key | Env var | Default | Meaning |
|---|---|---|---|
| `http.timeout` | `GATEWAY_HTTP_TIMEOUT` | `30` | Per-request timeout (seconds). |
| `http.retries` | `GATEWAY_HTTP_RETRIES` | `2` | Retry count for transient failures (408/429/5xx, connection timeouts). Attempts = retries + 1. `0` disables. |
| `http.retry_base_delay_ms` | `GATEWAY_HTTP_RETRY_BASE_MS` | `200` | Base delay (ms) for exponential backoff. |
| `http.logging` | `GATEWAY_HTTP_LOGGING` | `false` | Log request/response metadata (never headers/bodies) via PSR-3. |
| `http.rate_limit` | `GATEWAY_HTTP_RATE_LIMIT` | `false` | Enable the per-process token-bucket rate limiter. |
| `http.rate_limit_max_requests` | `GATEWAY_HTTP_RATE_LIMIT_MAX` | `10` | Tokens admitted per window (also burst size). |
| `http.rate_limit_per_seconds` | `GATEWAY_HTTP_RATE_LIMIT_PER` | `1` | Window length (seconds) for the bucket. |

### `commands.*` — console commands
| Key | Env var | Default | Meaning |
|---|---|---|---|
| `commands.reconcile` | `GATEWAY_RECONCILE_COMMANDS` | `true` | Register the `gateway:reconcile:{key}` commands. Disable if the host ships its own. |

### `events.*` — domain events
| Key | Env var | Default | Meaning |
|---|---|---|---|
| `events.enabled` | `GATEWAY_EVENTS` | `true` | Wrap drivers in `EventDispatchingGateway` to emit a `PaymentEvent` per lifecycle op. `false` returns bare drivers. |
| `events.log` | `GATEWAY_EVENTS_LOG` | `false` | Attach the built-in audit listener (`LoggingPaymentEventListener`): one redaction-safe line per event via PSR-3. |

### `logging.*` — the SDK's own logs
| Key | Env var | Default | Meaning |
|---|---|---|---|
| `logging.operations` | `GATEWAY_LOG_OPERATIONS` | `false` | Wrap drivers in `LoggingGateway` to log every call with duration + masked context. |
| `logging.channel` | `GATEWAY_LOG_CHANNEL` | `null` | Route SDK logs to a named channel from `config/logging.php`; `null` → dedicated daily `hyprpay-YYYY-MM-DD.log` under `storage/logs`. |
| `logging.days` | `GATEWAY_LOG_DAYS` | `14` | Retention (days) for the dedicated `hyprpay` channel. |
| `logging.level` | `GATEWAY_LOG_LEVEL` | `debug` | Minimum level for the dedicated `hyprpay` channel. |

### `gateways.{key}.*` — per-gateway credentials (fallback)
Read by `ConfigCredentialResolver` when no explicit `GatewayCredentials` are passed. Host resolution in `GatewayCredentials::fromConfig()`: explicit `host` if present, else `test_host` (test mode) / `live_host` (live), falling back to the CyberSource sandbox/live hostnames. `shared_secret` is the only field required for `isComplete()`; it is the base64 REST shared secret (base64-decoded before HMAC signing). To source credentials dynamically (DB, vault, per-tenant), pass them explicitly to the factory or bind a custom `CredentialResolver`.

#### `gateways.cybersource_uc`
| Key | Env var | Default |
|---|---|---|
| `test_mode` | `CYBERSOURCE_TEST_MODE` | `true` |
| `live_host` | `CYBERSOURCE_HOST` | `api.cybersource.com` |
| `test_host` | `CYBERSOURCE_TEST_HOST` | `apitest.cybersource.com` |
| `merchant_id` | `CYBERSOURCE_MERCHANT_ID` | `null` |
| `api_key_id` | `CYBERSOURCE_API_KEY_ID` | `null` |
| `shared_secret` | `CYBERSOURCE_SHARED_SECRET` | `null` |
| `webhook_secret` | `CYBERSOURCE_WEBHOOK_SHARED_SECRET` | `null` |
| `country` | `CYBERSOURCE_COUNTRY` | `EG` |
| `locale` | `CYBERSOURCE_LOCALE` | `en_US` |
| `currency` | `CYBERSOURCE_CURRENCY` | `EGP` |

#### `gateways.fawry`
| Key | Env var | Default |
|---|---|---|
| `test_mode` | `FAWRY_TEST_MODE` | `true` |
| `merchant_id` | `FAWRY_MERCHANT_CODE` | `null` |
| `shared_secret` | `FAWRY_SECURE_KEY` | `null` |
| `country` | `FAWRY_COUNTRY` | `EG` |
| `locale` | `FAWRY_LOCALE` | `en-gb` |
| `currency` | `FAWRY_CURRENCY` | `EGP` |

#### `gateways.paymob`
| Key | Env var | Default |
|---|---|---|
| `test_mode` | `PAYMOB_TEST_MODE` | `true` |
| `shared_secret` | `PAYMOB_API_KEY` | `null` |
| `webhook_secret` | `PAYMOB_HMAC_SECRET` | `null` |
| `currency` | `PAYMOB_CURRENCY` | `EGP` |
| `extra.integrations.card` | `PAYMOB_CARD_INTEGRATION_ID` | `null` |
| `extra.integrations.valu` | `PAYMOB_VALU_INTEGRATION_ID` | `null` |
| `extra.integrations.installment` | `PAYMOB_INSTALLMENT_INTEGRATION_ID` | `null` |
| `extra.iframes.card` | `PAYMOB_CARD_IFRAME_ID` | `null` |
| `extra.iframes.valu` | `PAYMOB_VALU_IFRAME_ID` | `null` |
| `extra.iframes.installment` | `PAYMOB_INSTALLMENT_IFRAME_ID` | `null` |

#### `gateways.paylink`
| Key | Env var | Default |
|---|---|---|
| `test_mode` | `PAYLINK_TEST_MODE` | `false` |
| `host` | `PAYLINK_HOST` | `pay.getpayin.com` |
| `merchant_id` | `PAYLINK_PUBLIC_TOKEN` | `null` |
| `shared_secret` | `PAYLINK_HASH_TOKEN` | `null` |
| `currency` | `PAYLINK_CURRENCY` | `USD` |

#### `gateways.paytabs`
| Key | Env var | Default |
|---|---|---|
| `test_mode` | `PAYTABS_TEST_MODE` | `true` |
| `host` | `PAYTABS_HOST` | `secure.paytabs.sa` |
| `merchant_id` | `PAYTABS_PROFILE_ID` | `null` |
| `shared_secret` | `PAYTABS_SERVER_KEY` | `null` |
| `webhook_secret` | `PAYTABS_SERVER_KEY` | `null` (same env as `shared_secret`) |
| `country` | `PAYTABS_COUNTRY` | `SA` |
| `locale` | `PAYTABS_LOCALE` | `en` |
| `currency` | `PAYTABS_CURRENCY` | `SAR` |

#### `gateways.paypal`
| Key | Env var | Default |
|---|---|---|
| `test_mode` | `PAYPAL_TEST_MODE` | `true` |
| `live_host` | `PAYPAL_HOST` | `api-m.paypal.com` |
| `test_host` | `PAYPAL_TEST_HOST` | `api-m.sandbox.paypal.com` |
| `merchant_id` | `PAYPAL_CLIENT_ID` | `null` |
| `shared_secret` | `PAYPAL_CLIENT_SECRET` | `null` |
| `webhook_secret` | `PAYPAL_WEBHOOK_ID` | `null` |
| `country` | `PAYPAL_COUNTRY` | `US` |
| `locale` | `PAYPAL_LOCALE` | `en-US` |
| `currency` | `PAYPAL_CURRENCY` | `USD` |

#### `gateways.mpgs`
| Key | Env var | Default |
|---|---|---|
| `test_mode` | `MPGS_TEST_MODE` | `true` |
| `host` | `MPGS_HOST` | `test-gateway.mastercard.com` |
| `merchant_id` | `MPGS_MERCHANT_ID` | `null` |
| `shared_secret` | `MPGS_API_PASSWORD` | `null` |
| `webhook_secret` | `MPGS_WEBHOOK_SECRET` | `null` |
| `country` | `MPGS_COUNTRY` | `US` |
| `locale` | `MPGS_LOCALE` | `en_US` |
| `currency` | `MPGS_CURRENCY` | `USD` |
| `extra.api_version` | `MPGS_API_VERSION` | `100` |

#### `gateways.authorize_net`
| Key | Env var | Default |
|---|---|---|
| `test_mode` | `AUTHORIZENET_TEST_MODE` | `true` |
| `live_host` | `AUTHORIZENET_HOST` | `api.authorize.net` |
| `test_host` | `AUTHORIZENET_TEST_HOST` | `apitest.authorize.net` |
| `merchant_id` | `AUTHORIZENET_LOGIN_ID` | `null` |
| `shared_secret` | `AUTHORIZENET_TRANSACTION_KEY` | `null` |
| `webhook_secret` | `AUTHORIZENET_SIGNATURE_KEY` | `null` |
| `country` | `AUTHORIZENET_COUNTRY` | `US` |
| `locale` | `AUTHORIZENET_LOCALE` | `en_US` |
| `currency` | `AUTHORIZENET_CURRENCY` | `USD` |

## Onward
- [overview.md](./overview.md) · [architecture.md](../architecture.md) · [observability.md](../observability.md) · [operations.md](../operations.md)
