<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Immutable DTO holding the per-gateway credentials and settings a driver needs.
 *
 * Bundles the API host, merchant and key identifiers, the shared secret used for
 * HMAC HTTP-Signature auth, the optional webhook secret, and localisation defaults
 * (country, locale, currency). Consumed by gateway drivers and the request-signing
 * trait; produced by the credential resolver.
 */
final readonly class GatewayCredentials
{
    /**
     * Hold the resolved credentials and settings for a single gateway.
     *
     * @param  string  $host  Gateway API hostname the driver sends requests to.
     * @param  string  $merchantId  Merchant identifier registered with the gateway.
     * @param  string  $apiKeyId  Public API key identifier used as the HTTP-Signature key id.
     * @param  string  $sharedSecret  Base64 shared secret used to compute the HMAC request signature.
     * @param  bool  $testMode  Whether the credentials target the gateway's sandbox/test environment.
     * @param  string|null  $webhookSecret  Secret used to verify inbound webhook signatures, or null when webhooks are not configured.
     * @param  string  $country  ISO country code applied to requests (defaults to EG).
     * @param  string  $locale  Locale used for the hosted checkout/UI (defaults to en_US).
     * @param  string  $currency  Default ISO currency code for transactions (defaults to EGP).
     * @param  array<string, mixed>  $extra  Gateway-specific configuration that does not fit the standard fields (e.g. Paymob per-method integration and iframe ids).
     */
    public function __construct(
        public string $host,
        public string $merchantId,
        public string $apiKeyId,
        public string $sharedSecret,
        public bool $testMode = true,
        public ?string $webhookSecret = null,
        public string $country = 'EG',
        public string $locale = 'en_US',
        public string $currency = 'EGP',
        public array $extra = [],
    ) {}

    /**
     * Build credentials from a raw gateway config array.
     *
     * Resolves the host from an explicit value or, based on test mode, the
     * configured test/live host (falling back to the CyberSource sandbox and live
     * hostnames), then coerces the remaining fields to their defaults.
     *
     * @param  array<string, mixed>  $config  Raw gateway configuration values keyed by setting name.
     */
    public static function fromConfig(array $config): self
    {
        $testMode = (bool) ($config['test_mode'] ?? true);

        $host = Value::string($config['host'] ?? match ($testMode) {
            true => $config['test_host'] ?? 'apitest.cybersource.com',
            false => $config['live_host'] ?? 'api.cybersource.com',
        });

        return new self(
            host: $host,
            merchantId: Value::string($config['merchant_id'] ?? null),
            apiKeyId: Value::string($config['api_key_id'] ?? null),
            sharedSecret: Value::string($config['shared_secret'] ?? null),
            testMode: $testMode,
            webhookSecret: isset($config['webhook_secret']) ? Value::string($config['webhook_secret']) : null,
            country: Value::string($config['country'] ?? null, 'EG'),
            locale: Value::string($config['locale'] ?? null, 'en_US'),
            currency: Value::string($config['currency'] ?? null, 'EGP'),
            extra: Value::array($config['extra'] ?? null),
        );
    }

    /**
     * Determine whether the primary credential common to every gateway is present.
     *
     * Requires a non-empty shared secret — the one field every supported gateway
     * needs (CyberSource HMAC secret, Fawry secure key, Paymob API key). Fields used
     * by only some gateways (merchant id, API key id, per-method integration ids) are
     * validated by the individual drivers at call time rather than here.
     */
    public function isComplete(): bool
    {
        return filled($this->sharedSecret);
    }

    /**
     * Read a gateway-specific value from the extra configuration bag by dot path.
     *
     * @param  mixed  $default
     * @return mixed
     */
    public function extra(string $key, $default = null)
    {
        return data_get($this->extra, $key, $default);
    }

    /**
     * Determine whether a webhook verification secret has been configured.
     */
    public function hasWebhookSecret(): bool
    {
        return filled($this->webhookSecret);
    }

    /**
     * Export the fields required by the HMAC request-signing trait.
     *
     * @return array{host: string, merchant_id: string, api_key_id: string, shared_secret: string}
     */
    public function toSigningArray(): array
    {
        return [
            'host' => $this->host,
            'merchant_id' => $this->merchantId,
            'api_key_id' => $this->apiKeyId,
            'shared_secret' => $this->sharedSecret,
        ];
    }
}
