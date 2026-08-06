<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads;

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawrySignature;

/**
 * Builds the FawryPay Auth/Capture capture request body.
 *
 * Targets POST /ECommerceWeb/api/payment/capture, which settles funds held by a prior
 * authorization (a charge sent with `authCaptureModePayment: true`). The authorization
 * is identified by its merchant reference number — the request's order reference,
 * falling back to its transaction id. The capture amount is always sent in FawryPay's
 * two-decimal format, so a smaller amount performs a partial capture. Note the
 * signature field is `requestSignature` (not `signature`).
 */
final class FawryCapturePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(CaptureRequest $request, GatewayCredentials $credentials): array
    {
        $merchantRefNum = $request->orderReference ?? $request->transactionId;
        $captureAmount = $request->money->toDecimalString();

        return [
            'merchantCode' => $credentials->merchantId,
            'merchantRefNum' => $merchantRefNum,
            'captureAmount' => $captureAmount,
            'requestSignature' => FawrySignature::capture(
                $merchantRefNum,
                $captureAmount,
                $credentials->merchantId,
                $credentials->sharedSecret,
            ),
        ];
    }
}
