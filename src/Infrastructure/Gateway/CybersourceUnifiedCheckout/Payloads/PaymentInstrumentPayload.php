<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\UpdatePaymentInstrumentRequest;

/**
 * Builds the CyberSource Token Management (TMS) request body for amending a stored payment
 * instrument.
 *
 * A partial PATCH: only the blocks the caller populated are emitted, so an update that just
 * re-dates a card does not restate its billing address. The card number is deliberately not
 * expressible — it lives on the instrument identifier behind the instrument, not on the
 * instrument itself.
 */
final class PaymentInstrumentPayload
{
    /**
     * Build the PATCH /tms/v2/customers/{id}/payment-instruments/{id} request body.
     *
     * @param  UpdatePaymentInstrumentRequest  $request  The fields to change on the stored instrument.
     * @return array<string, mixed>
     */
    public static function update(UpdatePaymentInstrumentRequest $request): array
    {
        $card = array_filter([
            'expirationMonth' => $request->expirationMonth,
            'expirationYear' => $request->expirationYear,
            'type' => $request->cardType,
        ], filled(...));

        $billTo = $request->billTo?->toArray() ?? [];

        $payload = [
            'card' => $card,
            'billTo' => $billTo,
        ];

        $payload = array_filter($payload, static fn (array $block): bool => $block !== []);

        if ($request->makeDefault !== null) {
            $payload['default'] = $request->makeDefault;
        }

        return $payload;
    }
}
