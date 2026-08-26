<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\PayerAuthSetupRequest;

/**
 * Builds the CyberSource Payer Authentication setup (3DS device data collection) request body.
 *
 * Used to prime device data collection for a tokenized card before enrollment; the response
 * carries the access token and device-data-collection URL the checkout page loads in a hidden
 * iframe to fingerprint the browser.
 */
final class PayerAuthSetupPayload
{
    /**
     * Build the POST /risk/v1/authentication-setups request body (3DS setup / DDC).
     *
     * Carries the client reference code and the card reference — the transient-token `jti`
     * claim when it can be read, otherwise the full transient-token JWT — under tokenInformation.
     *
     * @param  PayerAuthSetupRequest  $request  Setup inputs (transient token, optional order reference).
     * @param  string|null  $jti  The transient token's `jti` claim, when readable.
     * @return array<string, mixed>
     */
    public static function build(PayerAuthSetupRequest $request, ?string $jti): array
    {
        $tokenInformation = filled($jti)
            ? ['jti' => $jti]
            : ['transientToken' => $request->transientToken];

        return [
            'clientReferenceInformation' => [
                'code' => ClientReference::code($request->orderReference),
            ],
            'tokenInformation' => $tokenInformation,
        ];
    }
}
