<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\Pipes;

use Closure;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\PaymobCheckoutContext;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Payloads\PaymobOrderPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Second checkout step: register the order with Paymob and capture its id.
 *
 * Needs the auth token from {@see Authenticate}; the resulting order id is what the
 * payment-key step is bound to and what the finished session reports as its reference.
 */
final readonly class RegisterOrder
{
    /**
     * @param  Closure(PaymobCheckoutContext): PaymobCheckoutContext  $next
     */
    public function handle(PaymobCheckoutContext $context, Closure $next): PaymobCheckoutContext
    {
        $context->order = $context->client->post(
            '/ecommerce/orders',
            PaymobOrderPayload::build($context->request),
            $context->authToken,
            'register order',
        );

        $context->orderId = Value::string($context->order['id'] ?? null);

        return $next($context);
    }
}
