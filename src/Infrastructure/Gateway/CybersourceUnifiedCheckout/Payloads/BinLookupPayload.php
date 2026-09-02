<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\BinLookupRequest;

/**
 * Builds the CyberSource BIN Lookup request body.
 *
 * The credential can arrive five ways and the service takes them in two different blocks:
 * a transient token goes under `tokenInformation`, while a raw PAN and the vault references
 * all go under `paymentInformation`. Only what the caller supplied is sent, so a lookup by
 * token carries no card data at all.
 */
final class BinLookupPayload
{
    /**
     * Build the POST /bin/v1/binlookup request body.
     *
     * @param  BinLookupRequest  $request  The credential to inspect.
     * @return array<string, mixed>
     */
    public static function build(BinLookupRequest $request): array
    {
        $paymentInformation = array_filter([
            'card' => filled($request->cardNumber) ? ['number' => $request->cardNumber] : null,
            'customer' => self::reference($request->customerId),
            'paymentInstrument' => self::reference($request->paymentInstrumentId),
            'instrumentIdentifier' => self::reference($request->instrumentIdentifierId),
        ], static fn (?array $value): bool => $value !== null);

        $tokenInformation = array_filter([
            'transientTokenJwt' => $request->transientToken,
            'jti' => $request->transientTokenJti,
        ], filled(...));

        $payload = array_filter([
            'paymentInformation' => $paymentInformation,
            'tokenInformation' => $tokenInformation,
        ], static fn (array $block): bool => $block !== []);

        if (filled($request->orderReference)) {
            $payload['clientReferenceInformation'] = ['code' => ClientReference::code($request->orderReference)];
        }

        return $payload;
    }

    /**
     * A `{id: ...}` vault reference block, or null when the token was not supplied.
     *
     * @param  string|null  $id  Vault token identifier.
     * @return array<string, string>|null
     */
    private static function reference(?string $id): ?array
    {
        return filled($id) ? ['id' => $id] : null;
    }
}
