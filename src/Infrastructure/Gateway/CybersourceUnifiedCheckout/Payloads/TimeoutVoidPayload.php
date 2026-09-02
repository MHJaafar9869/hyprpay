<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\TimeoutVoidRequest;

/**
 * Builds the CyberSource timeout void / timeout reversal request body.
 *
 * There is no resource id to address, because the response that would have carried one never
 * arrived. The whole request is the merchant transaction id sent on the original call, which
 * CyberSource matches against to find and reverse it.
 */
final class TimeoutVoidPayload
{
    /**
     * Build the POST /pts/v2/voids (or /pts/v2/reversals) request body.
     *
     * @param  TimeoutVoidRequest  $request  The original request to reverse, identified by its merchant transaction id.
     * @return array<string, mixed>
     */
    public static function build(TimeoutVoidRequest $request): array
    {
        return [
            'clientReferenceInformation' => ClientReference::block(
                $request->orderReference,
                $request->merchantTransactionId,
                $request->merchantTransactionId,
            ),
        ];
    }
}
