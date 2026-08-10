<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;

/**
 * Builds the MPGS token-creation request body.
 *
 * Renders the card `sourceOfFunds` (and optional billing address) MPGS stores to issue a
 * reusable token that can later be charged as a stored credential.
 */
final class TokenizePayload
{
    /**
     * Build the POST /token request body.
     *
     * @param  TokenizeInstrumentRequest  $request  Card details to vault.
     * @return array<string, mixed>
     */
    public static function build(TokenizeInstrumentRequest $request): array
    {
        return array_filter([
            'sourceOfFunds' => MpgsPayloadParts::cardSourceOfFunds(
                $request->cardNumber,
                $request->expirationMonth,
                $request->expirationYear,
            ),
            'billing' => MpgsPayloadParts::billing($request->billTo),
        ], static fn (mixed $value): bool => $value !== []);
    }
}
