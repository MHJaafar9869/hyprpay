<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

/**
 * Result DTO holding a webhook security key the gateway created.
 *
 * The gateway signs every notification with this key, and it is the same secret the SDK checks
 * inbound notifications against — so {@see $key} is what belongs in the `webhookSecret`
 * credential.
 *
 * It is returned **once, at creation**, and cannot be read back afterwards: store it before
 * discarding the response or the subscription's notifications become unverifiable. Treat it as a
 * credential — never log it, and never return it from an application endpoint.
 */
final readonly class WebhookSecurityKey
{
    /**
     * @param  string|null  $keyId  Key serial number, referenced from a subscription's security config
     * @param  string|null  $key  The key value itself — shown once; store it as the `webhookSecret` credential
     * @param  string|null  $status  Gateway status of the key
     * @param  string|null  $keyType  Type of key created
     * @param  string|null  $organizationId  Organization the key belongs to
     * @param  string|null  $expiryDuration  How long the key remains valid, in days
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public ?string $keyId = null,
        public ?string $key = null,
        public ?string $status = null,
        public ?string $keyType = null,
        public ?string $organizationId = null,
        public ?string $expiryDuration = null,
        public array $raw = [],
    ) {}

    /**
     * Whether the gateway actually returned a key value to store.
     */
    public function hasKey(): bool
    {
        return filled($this->key);
    }
}
