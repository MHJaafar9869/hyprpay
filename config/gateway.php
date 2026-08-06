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
    | Gateway credentials (fallback)
    |--------------------------------------------------------------------------
    |
    | Used by ConfigCredentialResolver when no explicit GatewayCredentials are
    | passed to the factory. In a multi-tenant host application, bind a custom
    | CredentialResolver that reads per-tenant credentials instead.
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
    ],
];
