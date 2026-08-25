<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Tamara\Enums;

/**
 * The Tamara REST endpoints the driver calls, as paths relative to the API host.
 *
 * Tamara serves both environments from a single base host per environment
 * (api-sandbox.tamara.co for test, api.tamara.co for live), resolved from the
 * gateway credentials, so each case carries only the path — with %s placeholders
 * for path parameters such as the order id or merchant reference.
 */
enum TamaraEndpoint: string
{
    case Checkout = '/checkout';
    case Capture = '/payments/capture';
    case Refund = '/payments/simplified-refund/%s';
    case Authorise = '/orders/%s/authorise';
    case Cancel = '/orders/%s/cancel';
    case Order = '/orders/%s';
    case OrderByReference = '/merchants/orders/reference-id/%s';

    /**
     * Build the concrete request path, URL-encoding and interpolating any path parameters.
     *
     * @param  string  ...$args  Values for the path's %s placeholders (e.g. the order id), in order.
     * @return string The path ready to append to the API host.
     */
    public function path(string ...$args): string
    {
        return $args === []
            ? $this->value
            : vsprintf($this->value, array_map(rawurlencode(...), $args));
    }
}
