<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\AuthorizeNet\Payloads;

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Customer;

/**
 * Builds the `transactionRequest` bodies for Authorize.Net's createTransactionRequest calls.
 *
 * Authorize.Net converts the JSON to XML against an order-sensitive schema, so the child
 * elements MUST be emitted in this order: transactionType, amount, payment, order, customer,
 * billTo. PHP preserves array insertion order, so each builder lists the keys in that order;
 * optional blocks (order/customer/billTo) are dropped when empty without disturbing it.
 */
final class AuthorizeNetPayload
{
    /**
     * Accept.js opaque-data descriptor for an in-app payment nonce.
     */
    private const OPAQUE_DATA_DESCRIPTOR = 'COMMON.ACCEPT.INAPP.PAYMENT';

    /**
     * Build a charge (or auth-only) request from an Accept.js opaque-data token.
     *
     * The SDK's single `transientToken` is the Accept.js `dataValue` nonce; the descriptor
     * is the fixed in-app value. `capture` selects immediate capture vs authorization only.
     *
     * @return array<string, mixed>
     */
    public static function charge(ChargeRequest $request): array
    {
        return array_filter([
            'transactionType' => $request->capture ? 'authCaptureTransaction' : 'authOnlyTransaction',
            'amount' => $request->money->toDecimalString(),
            'payment' => [
                'opaqueData' => [
                    'dataDescriptor' => self::OPAQUE_DATA_DESCRIPTOR,
                    'dataValue' => $request->transientToken,
                ],
            ],
            'order' => self::order($request->orderReference),
            'customer' => self::customer($request->customer),
            'billTo' => self::billTo($request->billTo),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Build a prior-authorization capture against the original authorization's id.
     *
     * @return array<string, mixed>
     */
    public static function capture(CaptureRequest $request): array
    {
        return [
            'transactionType' => 'priorAuthCaptureTransaction',
            'amount' => $request->money->toDecimalString(),
            'refTransId' => $request->transactionId,
        ];
    }

    /**
     * Build a refund against the original charge's id (full or partial).
     *
     * Mirrors the live new-ubs integration: the settled transaction is referenced by id and
     * refunded by amount. This relies on the merchant account permitting referenced refunds
     * (e.g. Expanded Credit Capabilities); accounts without it may require the card's last four.
     *
     * @return array<string, mixed>
     */
    public static function refund(RefundRequest $request): array
    {
        return [
            'transactionType' => 'refundTransaction',
            'amount' => $request->money->toDecimalString(),
            'refTransId' => $request->transactionId,
        ];
    }

    /**
     * Build a void of the original (unsettled) transaction — carries no amount.
     *
     * @return array<string, mixed>
     */
    public static function void(VoidRequest $request): array
    {
        return [
            'transactionType' => 'voidTransaction',
            'refTransId' => $request->transactionId,
        ];
    }

    /**
     * Build the order block (invoice number capped at Authorize.Net's 20-char limit), or null.
     *
     * @return array<string, string>|null
     */
    private static function order(?string $reference): ?array
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        return ['invoiceNumber' => mb_substr($reference, 0, 20)];
    }

    /**
     * Build the customer block from the request's customer email, or null when absent.
     *
     * @return array<string, string>|null
     */
    private static function customer(?Customer $customer): ?array
    {
        $email = $customer?->email;

        return $email === null || $email === '' ? null : ['email' => $email];
    }

    /**
     * Build the billTo block from the billing address, dropping empty fields, or null.
     *
     * @return array<string, string>|null
     */
    private static function billTo(?BillingAddress $billTo): ?array
    {
        if (! $billTo instanceof BillingAddress) {
            return null;
        }

        $fields = array_filter([
            'firstName' => $billTo->firstName,
            'lastName' => $billTo->lastName,
            'address' => $billTo->address1,
            'city' => $billTo->locality,
            'state' => $billTo->administrativeArea,
            'zip' => $billTo->postalCode,
            'country' => $billTo->country,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $fields === [] ? null : $fields;
    }
}
