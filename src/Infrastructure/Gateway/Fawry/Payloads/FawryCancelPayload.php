<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads;

use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawrySignature;

/**
 * Builds the FawryPay Cancel Payment Authorization request body.
 *
 * Targets POST /ECommerceWeb/api/payment/cancel, which releases an Auth/Capture
 * authorization that has not been captured yet (FawryPay's analog of voiding a
 * payment). The authorization is identified by its merchant reference number — the
 * request's order reference, falling back to its transaction id. Note the signature
 * field is `requestSignature` (not `signature`, as the charge/refund endpoints use).
 *
 * The reference is taken verbatim from the request (no random or time-based suffix),
 * so an identical retry is byte-for-byte the same request.
 */
final class FawryCancelPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(VoidRequest $request, GatewayCredentials $credentials): array
    {
        $merchantRefNum = $request->orderReference ?? $request->transactionId;

        return [
            'merchantCode' => $credentials->merchantId,
            'merchantRefNum' => $merchantRefNum,
            'requestSignature' => FawrySignature::cancelAuthorization(
                $merchantRefNum,
                $credentials->merchantId,
                $credentials->sharedSecret,
            ),
        ];
    }
}
