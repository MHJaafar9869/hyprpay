<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawrySignature;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Builds the FawryPay Express Checkout hosted-page init request body.
 *
 * Targets POST /fawrypay-api/api/payments/init, whose success response is the hosted
 * checkout URL. The signature covers the merchant code, merchant reference, return URL,
 * and each charge item (itemId + quantity + price), followed by the secure key.
 *
 * The merchant reference is taken verbatim from the request's order reference (no random
 * or time-based suffix is added), so retrying with the same order reference produces an
 * identical request that FawryPay treats idempotently.
 */
final class FawryHostedPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
    {
        $amount = $request->money->toDecimalString();
        $returnUrl = $request->returnUrl ?? '';
        $chargeItems = FawryFields::chargeItems($request, $amount);

        $body = [
            'merchantCode' => $credentials->merchantId,
            'merchantRefNum' => FawryFields::merchantRefNum($request),
            'language' => FawryFields::language($request, $credentials),
            'returnUrl' => $returnUrl,
            'chargeItems' => $chargeItems,
            'description' => FawryFields::description($request),
        ];

        if (isset($request->options['webhook_url'])) {
            $body['orderWebHookUrl'] = Value::string($request->options['webhook_url']);
        }

        $body['signature'] = FawrySignature::hostedInit(
            $credentials->merchantId,
            FawryFields::merchantRefNum($request),
            $returnUrl,
            $chargeItems,
            $credentials->sharedSecret,
        );

        return $body;
    }
}
