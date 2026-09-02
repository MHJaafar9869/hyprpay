<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * How the gateway authenticates itself when calling your webhook endpoint.
 *
 * {@see self::Key} signs each notification with a shared symmetric key — the same secret the
 * SDK verifies inbound notifications against — and is what `verifyWebhook()` expects. The oAuth
 * variants make the gateway fetch a token from your authorization server instead, which the SDK
 * does not verify.
 */
enum WebhookSecurityType: string
{
    case Key = 'key';
    case OAuth = 'oAuth';
    case OAuthJwt = 'oAuth_JWT';

    /**
     * Whether notifications from this subscription carry a signature the SDK's
     * `verifyWebhook()` can check.
     */
    public function isSignatureVerifiable(): bool
    {
        return $this === self::Key;
    }
}
