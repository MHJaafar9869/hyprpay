<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Airwallex\Enums;

/**
 * The Airwallex "Online Payments" REST API paths the driver calls, appended to the host.
 *
 * The host is resolved from the credentials (api-demo.airwallex.com for the demo/sandbox,
 * api.airwallex.com for live), so the same paths serve both environments. Several cases
 * carry a `:id` segment ({@see PaymentIntent}, {@see PaymentIntentConfirm}, …) that
 * {@see AirwallexEndpoint::path()} substitutes with the concrete payment-intent id at call time.
 */
enum AirwallexEndpoint: string
{
    case Login = '/api/v1/authentication/login';
    case PaymentIntents = '/api/v1/pa/payment_intents/create';
    case PaymentIntentList = '/api/v1/pa/payment_intents';
    case PaymentIntent = '/api/v1/pa/payment_intents/:id';
    case PaymentIntentConfirm = '/api/v1/pa/payment_intents/:id/confirm';
    case PaymentIntentCapture = '/api/v1/pa/payment_intents/:id/capture';
    case PaymentIntentCancel = '/api/v1/pa/payment_intents/:id/cancel';
    case Refunds = '/api/v1/pa/refunds/create';
    case Refund = '/api/v1/pa/refunds/:id';
    case PaymentConsents = '/api/v1/pa/payment_consents/create';
    case PaymentConsentVerify = '/api/v1/pa/payment_consents/:id/verify';

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
