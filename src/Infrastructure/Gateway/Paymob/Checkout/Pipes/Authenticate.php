<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\Pipes;

use Closure;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\PaymobCheckoutContext;

/**
 * First checkout step: exchange the merchant API key for a short-lived Paymob auth token.
 *
 * Every later Paymob call in the flow is bearer-authenticated with this token, so it must
 * run before the order and payment-key steps.
 */
final readonly class Authenticate
{
    /**
     * @param  Closure(PaymobCheckoutContext): PaymobCheckoutContext  $next
     */
    public function handle(PaymobCheckoutContext $context, Closure $next): PaymobCheckoutContext
    {
        $context->authToken = $context->client->authenticate();

        return $next($context);
    }
}
