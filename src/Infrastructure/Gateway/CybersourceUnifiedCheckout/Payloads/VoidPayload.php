<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\VoidRequest;

/**
 * Builds the CyberSource void request body.
 *
 * Used to void a capture, credit, or reversal before it settles, referencing the original
 * transaction by its id in the endpoint path.
 */
final class VoidPayload
{
    /**
     * Build the POST /pts/v2/payments/{id}/voids request body.
     *
     * Carries only the client reference correlation code; the transaction to void is
     * identified by the id in the endpoint path.
     *
     * @param  VoidRequest  $request  Void inputs (order reference and original transaction id).
     * @return array<string, mixed>
     */
    public static function build(VoidRequest $request): array
    {
        return [
            'clientReferenceInformation' => [
                'code' => ClientReference::code($request->orderReference, $request->transactionId),
            ],
        ];
    }
}
