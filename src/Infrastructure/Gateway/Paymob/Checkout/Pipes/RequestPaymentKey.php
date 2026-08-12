<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\Pipes;

use Closure;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\PaymobCheckoutContext;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Payloads\PaymobPaymentKeyPayload;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Third checkout step: request the payment key that the iframe redirect is built from.
 *
 * Binds the auth token, the registered order, and the resolved integration id together;
 * must therefore run after {@see Authenticate} and {@see RegisterOrder}. The payment-key
 * lifetime comes from the request options, falling back to {@see DEFAULT_EXPIRATION_SECONDS}.
 */
final readonly class RequestPaymentKey
{
    private const DEFAULT_EXPIRATION_SECONDS = 3600;

    /**
     * @param  Closure(PaymobCheckoutContext): PaymobCheckoutContext  $next
     */
    public function handle(PaymobCheckoutContext $context, Closure $next): PaymobCheckoutContext
    {
        $paymentKey = $context->client->post(
            '/acceptance/payment_keys',
            PaymobPaymentKeyPayload::build(
                $context->request,
                $context->orderId,
                $context->authToken,
                $context->integrationId,
                PaymobCheckoutOptions::fromRequest($context->request)->expiration ?? self::DEFAULT_EXPIRATION_SECONDS,
            ),
            $context->authToken,
            'create payment key',
        );

        $context->paymentToken = Value::string($paymentKey['token'] ?? null);

        return $next($context);
    }
}
