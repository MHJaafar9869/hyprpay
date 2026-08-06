<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;

/**
 * Builds the CyberSource Token Management Service (TMS) request bodies used to save a card.
 *
 * Provides the three request shapes for the tokenization flow: creating an instrument
 * identifier from the raw card number, creating a customer profile, and attaching a
 * payment instrument (card + billing) to that customer under an instrument identifier.
 */
final class TokenizePayload
{
    /**
     * Build the POST /tms/v1/instrumentidentifiers request body.
     *
     * Carries the raw card number that CyberSource tokenizes into a reusable instrument identifier.
     *
     * @param  TokenizeInstrumentRequest  $request  Tokenization inputs (card number).
     * @return array<string, mixed>
     */
    public static function instrumentIdentifier(TokenizeInstrumentRequest $request): array
    {
        return [
            'card' => [
                'number' => $request->cardNumber,
            ],
        ];
    }

    /**
     * Build the POST /tms/v2/customers request body.
     *
     * Carries the client reference code and, when present, the merchant customer id under
     * buyerInformation to create the customer profile.
     *
     * @param  TokenizeInstrumentRequest  $request  Tokenization inputs (customer reference).
     * @return array<string, mixed>
     */
    public static function customer(TokenizeInstrumentRequest $request): array
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => ClientReference::code($request->customerReference),
            ],
        ];

        if (filled($request->customerReference)) {
            $payload['buyerInformation'] = ['merchantCustomerID' => $request->customerReference];
        }

        return $payload;
    }

    /**
     * Build the POST /tms/v2/customers/{id}/payment-instruments request body.
     *
     * Carries the card expiry (and optional card type), links the payment instrument to the
     * supplied instrument identifier, and includes the optional billing address.
     *
     * @param  TokenizeInstrumentRequest  $request  Tokenization inputs (expiration month/year, optional card type, optional billTo).
     * @param  string  $instrumentIdentifierId  Instrument identifier id returned by instrumentIdentifier() to bind the card to.
     * @return array<string, mixed>
     */
    public static function paymentInstrument(TokenizeInstrumentRequest $request, string $instrumentIdentifierId): array
    {
        $card = [
            'expirationMonth' => $request->expirationMonth,
            'expirationYear' => $request->expirationYear,
        ];

        if (filled($request->cardType)) {
            $card['type'] = $request->cardType;
        }

        $payload = [
            'card' => $card,
            'instrumentIdentifier' => ['id' => $instrumentIdentifierId],
        ];

        $billTo = $request->billTo?->toArray() ?? [];

        if (filled($billTo)) {
            $payload['billTo'] = $billTo;
        }

        return $payload;
    }
}
