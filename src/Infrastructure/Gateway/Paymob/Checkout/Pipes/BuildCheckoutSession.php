<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\Pipes;

use Closure;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout\PaymobCheckoutContext;

/**
 * Final checkout step: assemble the CheckoutSession the gateway returns.
 *
 * Combines the outputs of the earlier steps — the order id and payment token — with the
 * resolved iframe id to produce the redirect URL. When no iframe id is configured the
 * redirect URL is null and the caller drives the payment token itself.
 */
final readonly class BuildCheckoutSession
{
    private const IFRAME_URL = 'https://accept.paymob.com/api/acceptance/iframes/%s?payment_token=%s';

    /**
     * @param  Closure(PaymobCheckoutContext): PaymobCheckoutContext  $next
     */
    public function handle(PaymobCheckoutContext $context, Closure $next): PaymobCheckoutContext
    {
        $context->session = new CheckoutSession(
            redirectUrl: filled($context->iframeId) ? sprintf(self::IFRAME_URL, $context->iframeId, $context->paymentToken) : null,
            reference: filled($context->orderId) ? $context->orderId : null,
            merchantReference: $context->request->orderReference,
            raw: ['order' => $context->order, 'payment_token' => $context->paymentToken],
        );

        return $next($context);
    }
}
