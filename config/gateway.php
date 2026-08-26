<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | The gateway used when the factory is asked for a driver without an
    | explicit GatewayName. Must match a Hyprpay\Payments\Domain\Enum\GatewayName value.
    |
    */
    'default' => env('GATEWAY_DEFAULT', 'cybersource_uc'),

    /*
    |--------------------------------------------------------------------------
    | HTTP transport
    |--------------------------------------------------------------------------
    |
    | Applies to every gateway. Transient failures (HTTP 408/429/5xx and
    | connection timeouts) are retried with exponential backoff — safe because
    | requests are deterministic and idempotent. Set retries to 0 to disable.
    | Enable logging to record request/response metadata (never headers or
    | bodies) through the application's PSR-3 logger.
    |
    | Rate limiting throttles outbound requests with a per-process token bucket
    | so the SDK stays under a gateway's published limit instead of provoking
    | 429s. It admits max_requests per per_seconds window (also the burst size);
    | when the bucket empties, calls block just long enough for a token to accrue.
    |
    */
    'http' => [
        'timeout' => (int) env('GATEWAY_HTTP_TIMEOUT', 30),
        'retries' => (int) env('GATEWAY_HTTP_RETRIES', 2),
        'retry_base_delay_ms' => (int) env('GATEWAY_HTTP_RETRY_BASE_MS', 200),
        'logging' => (bool) env('GATEWAY_HTTP_LOGGING', false),
        'rate_limit' => (bool) env('GATEWAY_HTTP_RATE_LIMIT', false),
        'rate_limit_max_requests' => (int) env('GATEWAY_HTTP_RATE_LIMIT_MAX', 10),
        'rate_limit_per_seconds' => (int) env('GATEWAY_HTTP_RATE_LIMIT_PER', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Console commands
    |--------------------------------------------------------------------------
    |
    | Toggle the per-gateway reconciliation Artisan commands
    | (gateway:reconcile:{gateway}), which fetch the authoritative status of
    | one or more transaction ids straight from the gateway. Disable this if
    | the host application ships its own reconciliation tooling.
    |
    */
    'commands' => [
        'reconcile' => (bool) env('GATEWAY_RECONCILE_COMMANDS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain events
    |--------------------------------------------------------------------------
    |
    | When enabled, every gateway driver is wrapped so it emits a payment domain
    | event after each lifecycle operation (charge, capture, refund, void,
    | reversal, stored-credential charge, vaulting, checkout, webhook). Listen for
    | a specific event class or for the Hyprpay\Payments\Domain\Event\PaymentEvent
    | interface to receive them all. With no listeners registered the overhead is
    | negligible; set enabled to false to return bare drivers with no dispatch.
    |
    | Enable "log" to attach the built-in audit listener, which records a
    | redaction-safe line per event (gateway, ids, status — never card data or raw
    | payloads) through the application's PSR-3 logger.
    |
    */
    'events' => [
        'enabled' => (bool) env('GATEWAY_EVENTS', true),
        'log' => (bool) env('GATEWAY_EVENTS_LOG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The SDK writes its own logs — operation logs (when `operations` is on, each
    | gateway driver is wrapped in a LoggingGateway that logs every call with its
    | duration and safe, masked context) and the event audit log (see events.log)
    | — to their own channel, kept out of your default application log.
    |
    | Leave `channel` null to log to a dedicated daily hyprpay-YYYY-MM-DD.log file
    | under storage/logs (retained for `days`); set a channel name to route the
    | logs into a channel you have defined in config/logging.php instead. This is
    | distinct from `http.logging`, which logs lower-level HTTP metadata.
    |
    */
    'logging' => [
        'operations' => (bool) env('GATEWAY_LOG_OPERATIONS', false),
        'channel' => env('GATEWAY_LOG_CHANNEL'),
        'days' => (int) env('GATEWAY_LOG_DAYS', 14),
        'level' => env('GATEWAY_LOG_LEVEL', 'debug'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring dashboard
    |--------------------------------------------------------------------------
    |
    | An opt-in operator dashboard that mounts its own routes to visualise gateway
    | activity: which gateways are configured and in which mode, a live feed of the
    | recent lifecycle operations, aggregate stats, and an on-demand "look up a
    | payment by reference" panel that queries the gateway directly. It is off by
    | default; the SDK ships no UI unless you enable it here.
    |
    | Access is gated exactly like Telescope/Horizon: every request must satisfy the
    | `viewHyprpay` authorization gate (default: allowed only in the local
    | environment — override it in a service provider to open it to real operators)
    | AND pass the configured `middleware` stack. `path` is the URL prefix the
    | dashboard is served from (default /hyprpay).
    |
    | The activity feed reads from a store fed by an event listener; it is a
    | separate toggle so you can mount the dashboard without recording anything.
    | Three drivers are available via GATEWAY_DASHBOARD_STORE_DRIVER:
    |
    |   database  Durable, normalized, PII-safe tables (payments, their attempts,
    |             and webhooks) that the dashboard reads. This is the default; run
    |             `php artisan hyprpay:install --migrate` to create the tables.
    |   cache     A bounded ring buffer in the cache — no database, no migration —
    |             keeping the last `limit` records.
    |   null      Discard everything (an explicit no-op store).
    |
    | Or bind your own PaymentActivityRepository to persist history however you like.
    |
    */
    'dashboard' => [
        'enabled' => (bool) env('GATEWAY_DASHBOARD', false),
        'path' => env('GATEWAY_DASHBOARD_PATH', 'hyprpay'),
        'middleware' => ['web'],
        'store' => [
            'enabled' => (bool) env('GATEWAY_DASHBOARD_STORE', false),
            'driver' => env('GATEWAY_DASHBOARD_STORE_DRIVER', 'database'),
            'cache' => env('GATEWAY_DASHBOARD_CACHE_STORE'),
            'key' => env('GATEWAY_DASHBOARD_CACHE_KEY', 'hyprpay:activity'),
            'limit' => (int) env('GATEWAY_DASHBOARD_LIMIT', 500),
            'database' => [
                'connection' => env('GATEWAY_DASHBOARD_DB_CONNECTION'),
                'prefix' => env('GATEWAY_DASHBOARD_DB_PREFIX', 'hyprpay_'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway credentials (fallback)
    |--------------------------------------------------------------------------
    |
    | Used by ConfigCredentialResolver when no explicit GatewayCredentials are
    | passed to the factory — a single set per gateway by default. To source
    | credentials dynamically (from a database, a secrets vault, or per-tenant/
    | per-merchant), pass them explicitly to the factory or bind a custom
    | CredentialResolver.
    |
    | The shared secret is the base64-encoded REST shared secret exactly as
    | issued by CyberSource; it is base64-decoded before HMAC signing.
    |
    */
    'gateways' => [
        'cybersource_uc' => [
            'test_mode' => (bool) env('CYBERSOURCE_TEST_MODE', true),
            'live_host' => env('CYBERSOURCE_HOST', 'api.cybersource.com'),
            'test_host' => env('CYBERSOURCE_TEST_HOST', 'apitest.cybersource.com'),
            'merchant_id' => env('CYBERSOURCE_MERCHANT_ID'),
            'api_key_id' => env('CYBERSOURCE_API_KEY_ID'),
            'shared_secret' => env('CYBERSOURCE_SHARED_SECRET'),
            'webhook_secret' => env('CYBERSOURCE_WEBHOOK_SHARED_SECRET'),

            'country' => env('CYBERSOURCE_COUNTRY', 'EG'),
            'locale' => env('CYBERSOURCE_LOCALE', 'en_US'),
            'currency' => env('CYBERSOURCE_CURRENCY', 'EGP'),
        ],

        'fawry' => [
            'test_mode' => (bool) env('FAWRY_TEST_MODE', true),
            'merchant_id' => env('FAWRY_MERCHANT_CODE'),
            'shared_secret' => env('FAWRY_SECURE_KEY'),

            'country' => env('FAWRY_COUNTRY', 'EG'),
            'locale' => env('FAWRY_LOCALE', 'en-gb'),
            'currency' => env('FAWRY_CURRENCY', 'EGP'),
        ],

        'paymob' => [
            'test_mode' => (bool) env('PAYMOB_TEST_MODE', true),
            'shared_secret' => env('PAYMOB_API_KEY'),
            'webhook_secret' => env('PAYMOB_HMAC_SECRET'),

            'currency' => env('PAYMOB_CURRENCY', 'EGP'),

            'extra' => [
                'integrations' => [
                    'card' => env('PAYMOB_CARD_INTEGRATION_ID'),
                    'valu' => env('PAYMOB_VALU_INTEGRATION_ID'),
                    'installment' => env('PAYMOB_INSTALLMENT_INTEGRATION_ID'),
                ],
                'iframes' => [
                    'card' => env('PAYMOB_CARD_IFRAME_ID'),
                    'valu' => env('PAYMOB_VALU_IFRAME_ID'),
                    'installment' => env('PAYMOB_INSTALLMENT_IFRAME_ID'),
                ],
            ],
        ],

        'paylink' => [
            'test_mode' => (bool) env('PAYLINK_TEST_MODE', false),
            'host' => env('PAYLINK_HOST', 'pay.getpayin.com'),
            'merchant_id' => env('PAYLINK_PUBLIC_TOKEN'),
            'shared_secret' => env('PAYLINK_HASH_TOKEN'),

            'currency' => env('PAYLINK_CURRENCY', 'USD'),
        ],

        'paytabs' => [
            'test_mode' => (bool) env('PAYTABS_TEST_MODE', true),
            'host' => env('PAYTABS_HOST', 'secure.paytabs.sa'),
            'merchant_id' => env('PAYTABS_PROFILE_ID'),
            'shared_secret' => env('PAYTABS_SERVER_KEY'),
            'webhook_secret' => env('PAYTABS_SERVER_KEY'),

            'country' => env('PAYTABS_COUNTRY', 'SA'),
            'locale' => env('PAYTABS_LOCALE', 'en'),
            'currency' => env('PAYTABS_CURRENCY', 'SAR'),
        ],

        'paypal' => [
            'test_mode' => (bool) env('PAYPAL_TEST_MODE', true),
            'live_host' => env('PAYPAL_HOST', 'api-m.paypal.com'),
            'test_host' => env('PAYPAL_TEST_HOST', 'api-m.sandbox.paypal.com'),
            'merchant_id' => env('PAYPAL_CLIENT_ID'),
            'shared_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_secret' => env('PAYPAL_WEBHOOK_ID'),

            'country' => env('PAYPAL_COUNTRY', 'US'),
            'locale' => env('PAYPAL_LOCALE', 'en-US'),
            'currency' => env('PAYPAL_CURRENCY', 'USD'),
        ],

        'mpgs' => [
            'test_mode' => (bool) env('MPGS_TEST_MODE', true),
            'host' => env('MPGS_HOST', 'test-gateway.mastercard.com'),
            'merchant_id' => env('MPGS_MERCHANT_ID'),
            'shared_secret' => env('MPGS_API_PASSWORD'),
            'webhook_secret' => env('MPGS_WEBHOOK_SECRET'),

            'country' => env('MPGS_COUNTRY', 'US'),
            'locale' => env('MPGS_LOCALE', 'en_US'),
            'currency' => env('MPGS_CURRENCY', 'USD'),

            'extra' => [
                'api_version' => env('MPGS_API_VERSION', '100'),
            ],
        ],

        'authorize_net' => [
            'test_mode' => (bool) env('AUTHORIZENET_TEST_MODE', true),
            'live_host' => env('AUTHORIZENET_HOST', 'api.authorize.net'),
            'test_host' => env('AUTHORIZENET_TEST_HOST', 'apitest.authorize.net'),
            'merchant_id' => env('AUTHORIZENET_LOGIN_ID'),
            'shared_secret' => env('AUTHORIZENET_TRANSACTION_KEY'),
            'webhook_secret' => env('AUTHORIZENET_SIGNATURE_KEY'),

            'country' => env('AUTHORIZENET_COUNTRY', 'US'),
            'locale' => env('AUTHORIZENET_LOCALE', 'en_US'),
            'currency' => env('AUTHORIZENET_CURRENCY', 'USD'),
        ],

        'airwallex' => [
            'test_mode' => (bool) env('AIRWALLEX_TEST_MODE', true),
            'live_host' => env('AIRWALLEX_HOST', 'api.airwallex.com'),
            'test_host' => env('AIRWALLEX_TEST_HOST', 'api-demo.airwallex.com'),
            'merchant_id' => env('AIRWALLEX_CLIENT_ID'),
            'shared_secret' => env('AIRWALLEX_API_KEY'),
            'webhook_secret' => env('AIRWALLEX_WEBHOOK_SECRET'),

            'country' => env('AIRWALLEX_COUNTRY', 'US'),
            'locale' => env('AIRWALLEX_LOCALE', 'en'),
            'currency' => env('AIRWALLEX_CURRENCY', 'USD'),

            'extra' => [
                'api_version' => env('AIRWALLEX_API_VERSION', '2025-11-11'),
                'account_id' => env('AIRWALLEX_ACCOUNT_ID'),
            ],
        ],

        'tamara' => [
            'test_mode' => (bool) env('TAMARA_TEST_MODE', true),
            'live_host' => env('TAMARA_HOST', 'api.tamara.co'),
            'test_host' => env('TAMARA_TEST_HOST', 'api-sandbox.tamara.co'),
            'merchant_id' => env('TAMARA_MERCHANT_ID'),
            'shared_secret' => env('TAMARA_API_TOKEN'),
            'webhook_secret' => env('TAMARA_NOTIFICATION_TOKEN'),

            'country' => env('TAMARA_COUNTRY', 'SA'),
            'locale' => env('TAMARA_LOCALE', 'en_US'),
            'currency' => env('TAMARA_CURRENCY', 'SAR'),

            'extra' => [
                'payment_type' => env('TAMARA_PAYMENT_TYPE', 'PAY_BY_INSTALMENTS'),
                'instalments' => env('TAMARA_INSTALMENTS'),
            ],
        ],
    ],
];
