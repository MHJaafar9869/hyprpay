<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\Command\ValidateBankAccountRequest;

/**
 * Builds the Visa Bank Account Validation Service (BAVS) request body.
 *
 * The service takes the account to check in one of two shapes and the payload sends whichever
 * the caller supplied: the raw `bank` block (routing number plus account number) or a vault
 * reference — a customer, payment-instrument, or instrument-identifier token — which makes the
 * bank block optional because the gateway reads the stored account behind the token.
 *
 * `processingInformation.validationLevel` is required by the service and always sent.
 */
final class AccountValidationPayload
{
    /**
     * Build the POST /bavs/v1/account-validations request body.
     *
     * @param  ValidateBankAccountRequest  $request  The account to validate, as raw bank details or a vault token.
     * @return array<string, mixed>
     */
    public static function build(ValidateBankAccountRequest $request): array
    {
        $paymentInformation = array_filter([
            'bank' => self::bank($request),
            'customer' => self::reference($request->customerId),
            'paymentInstrument' => self::reference($request->paymentInstrumentId),
            'instrumentIdentifier' => self::reference($request->instrumentIdentifierId),
        ], static fn (?array $value): bool => $value !== null);

        $payload = [
            'processingInformation' => ['validationLevel' => $request->validationLevel],
            'paymentInformation' => $paymentInformation,
        ];

        if (filled($request->orderReference)) {
            $payload['clientReferenceInformation'] = ['code' => ClientReference::code($request->orderReference)];
        }

        return $payload;
    }

    /**
     * The `paymentInformation.bank` block, or null when the caller referenced a vaulted account
     * instead of supplying raw bank details. Both fields are required together, so a partial
     * pair sends nothing rather than a half-formed block the service would reject.
     *
     * @return array<string, mixed>|null
     */
    private static function bank(ValidateBankAccountRequest $request): ?array
    {
        if (blank($request->routingNumber) || blank($request->accountNumber)) {
            return null;
        }

        return [
            'routingNumber' => $request->routingNumber,
            'account' => ['number' => $request->accountNumber],
        ];
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
