<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums;

/**
 * The PayPal REST API paths the driver calls, appended to the api-m host.
 *
 * The host is resolved from the credentials (api-m.sandbox.paypal.com for the
 * sandbox, api-m.paypal.com for live), so the same paths serve both environments.
 * Several cases carry a `:placeholder` segment ({@see OrderCapture}, {@see AuthorizationCapture},
 * …) that {@see PayPalEndpoint::path()} substitutes with the concrete order,
 * authorization, capture, or refund id at call time.
 */
enum PayPalEndpoint: string
{
    case OAuthToken = '/v1/oauth2/token';
    case Orders = '/v2/checkout/orders';
    case Order = '/v2/checkout/orders/:id';
    case OrderCapture = '/v2/checkout/orders/:id/capture';
    case OrderAuthorize = '/v2/checkout/orders/:id/authorize';
    case AuthorizationCapture = '/v2/payments/authorizations/:id/capture';
    case AuthorizationVoid = '/v2/payments/authorizations/:id/void';
    case Authorization = '/v2/payments/authorizations/:id';
    case CaptureRefund = '/v2/payments/captures/:id/refund';
    case Capture = '/v2/payments/captures/:id';
    case Refund = '/v2/payments/refunds/:id';
    case SetupTokens = '/v3/vault/setup-tokens';
    case PaymentTokens = '/v3/vault/payment-tokens';
    case VerifyWebhookSignature = '/v1/notifications/verify-webhook-signature';

    /**
     * Render the endpoint path, substituting the `:id` placeholder when present.
     *
     * @param  string  $id  The resource id to substitute for the `:id` segment.
     */
    public function path(string $id = ''): string
    {
        return str_replace(':id', $id, $this->value);
    }
}
