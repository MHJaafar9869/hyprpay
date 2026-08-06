<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;

/**
 * Builds the Paymob payment-key request body (POST /acceptance/payment_keys).
 *
 * Ties the registered order to the chosen integration and the payer's billing data,
 * producing the payment token embedded in the Paymob iframe URL. The amount matches
 * the order's amount (Money's minor amount) exactly.
 */
final class PaymobPaymentKeyPayload
{
    /**
     * @param  int|string  $orderId  The Paymob order id returned by order registration.
     * @param  string  $authToken  The Paymob auth token from authentication.
     * @param  string  $integrationId  The per-method Paymob integration id.
     * @param  int  $expirationSeconds  Payment session lifetime in seconds.
     * @return array<string, mixed>
     */
    public static function build(
        CheckoutSessionRequest $request,
        int|string $orderId,
        string $authToken,
        string $integrationId,
        int $expirationSeconds,
    ): array {
        return [
            'auth_token' => $authToken,
            'amount_cents' => $request->money->minorAmount,
            'expiration' => $expirationSeconds,
            'order_id' => $orderId,
            'billing_data' => PaymobBillingData::fromRequest($request),
            'currency' => $request->money->currency,
            'integration_id' => $integrationId,
            'lock_order_when_paid' => false,
        ];
    }
}
