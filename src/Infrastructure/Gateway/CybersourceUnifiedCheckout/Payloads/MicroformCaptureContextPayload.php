<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;

/**
 * Builds the CyberSource Flex Microform v2 capture-context request body.
 *
 * Microform is the low-level, PCI-friendly card-field tokenizer (distinct from the full
 * Unified Checkout widget): the returned JWT context only configures the secure card fields
 * — the browser origins allowed to launch Microform and the card networks to accept — and
 * carries no order amount, capture mandate, or UI mandate, since the amount is applied later
 * when the resulting transient token is charged. The request is therefore a near-subset of
 * {@see CaptureContextPayload}'s: `targetOrigins`, `allowedCardNetworks`, and
 * `allowedPaymentTypes` (fixed to `CARD` — this driver integrates Microform for cards), plus
 * an optional `orderInformation.billTo` when the caller supplies a billing address so the
 * cardholder's billing details are captured for AVS and risk screening.
 *
 * `clientVersion` is deliberately omitted: the SDK's shared checkout `clientVersion` default
 * targets Unified Checkout, so Microform is left to CyberSource's default (latest) client
 * version rather than sent a version meant for a different product.
 */
final class MicroformCaptureContextPayload
{
    /**
     * Build the POST /microform/v2/sessions request body.
     *
     * @param  CheckoutSessionRequest  $request  Checkout session inputs; `targetOrigins` and `allowedCardNetworks` are read for Microform, plus the optional `billTo` billing address when present.
     * @return array<string, mixed>
     */
    public static function build(CheckoutSessionRequest $request): array
    {
        $payload = array_filter([
            'targetOrigins' => $request->targetOrigins,
            'allowedCardNetworks' => $request->allowedCardNetworks,
            'allowedPaymentTypes' => ['CARD'],
        ], static fn (array $value): bool => $value !== []);

        $billTo = $request->billTo?->toArray() ?? [];

        if (filled($billTo)) {
            $payload['orderInformation'] = ['billTo' => $billTo];
        }

        return $payload;
    }
}
