<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Checkout;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobClient;

/**
 * Carries state through the Paymob checkout pipeline as each pipe fills in its part.
 *
 * The readonly fields are the inputs resolved before the pipeline runs: the request, the
 * Paymob client, and the per-method integration and iframe ids (integration id is resolved
 * up front so a misconfigured method fails before any network call). The remaining fields
 * are written by the pipes in order — {@see Pipes\Authenticate} sets the auth token,
 * {@see Pipes\RegisterOrder} the order and its id, {@see Pipes\RequestPaymentKey} the payment
 * token, and {@see Pipes\BuildCheckoutSession} the final session that the gateway returns.
 */
final class PaymobCheckoutContext
{
    /**
     * The registered Paymob order, as returned by the orders endpoint.
     *
     * @var array<string, mixed>
     */
    public array $order = [];

    /**
     * The Paymob order id extracted from the registered order.
     */
    public string $orderId = '';

    /**
     * The short-lived Paymob auth token used to authenticate the order and payment-key calls.
     */
    public string $authToken = '';

    /**
     * The payment token used to build the iframe redirect URL.
     */
    public string $paymentToken = '';

    /**
     * The finished checkout session, set by the last pipe and returned by the gateway.
     */
    public ?CheckoutSession $session = null;

    /**
     * @param  CheckoutSessionRequest  $request  The checkout request being fulfilled.
     * @param  PaymobClient  $client  The Paymob API client the pipes call through.
     * @param  string  $integrationId  The resolved Paymob integration id for the chosen method.
     * @param  string|null  $iframeId  The resolved Paymob iframe id, or null when none is configured.
     */
    public function __construct(
        public readonly CheckoutSessionRequest $request,
        public readonly PaymobClient $client,
        public readonly string $integrationId,
        public readonly ?string $iframeId,
    ) {}
}
