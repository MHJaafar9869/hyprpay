<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsApiOperation;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\MpgsCheckoutOptions;

/**
 * Builds the MPGS Hosted Checkout `INITIATE_CHECKOUT` session request body.
 *
 * Renders the interaction block (operation, merchant display details, return URL) and the
 * order block (id, amount, currency, description) MPGS needs to create a hosted checkout
 * session the customer completes on the gateway-hosted page.
 */
final class HostedCheckoutPayload
{
    /**
     * Build the POST /session INITIATE_CHECKOUT request body.
     *
     * @param  CheckoutSessionRequest  $request  Checkout inputs (amount, description, options).
     * @param  string  $orderId  Resolved MPGS order id to create the session for.
     * @return array<string, mixed>
     */
    public static function build(CheckoutSessionRequest $request, string $orderId): array
    {
        $options = MpgsCheckoutOptions::fromRequest($request);

        $merchant = array_filter([
            'name' => $options->merchantName,
            'url' => $options->merchantUrl,
        ], static fn (mixed $value): bool => $value !== null);

        $interaction = array_filter([
            'operation' => $options->operation ?? 'PURCHASE',
            'merchant' => $merchant === [] ? null : $merchant,
            'returnUrl' => $options->returnUrl ?? $request->returnUrl,
        ], static fn (mixed $value): bool => $value !== null);

        $order = array_filter([
            'id' => $orderId,
            'amount' => $request->money->toDecimalString(),
            'currency' => $request->money->currency,
            'description' => $request->description,
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'apiOperation' => MpgsApiOperation::InitiateCheckout->value,
            'checkoutMode' => $options->checkoutMode ?? 'WEBSITE',
            'interaction' => $interaction,
            'order' => $order,
        ];
    }
}
