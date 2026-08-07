<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paylink;

/**
 * The PayLink Payment Integration endpoints the driver signs and calls.
 *
 * Each case's backing value is its request path, and {@see fields()} returns the
 * request fields in the EXACT order the server concatenates them when it rebuilds
 * the HMAC signature (the FormRequest `rules()` order, minus `token`/`signature`).
 * Fields flagged unsigned (`payment_mode`, `iframe`) are sent in the body but
 * excluded from the signature. This mirrors the shared golden-signature contract
 * used by every PayLink SDK.
 */
enum PaylinkEndpoint: string
{
    case InvoiceCreate = '/api/v2/integration/init';
    case Void = '/api/integration/void';
    case Refund = '/api/integration/refund';
    case Settle = '/api/integration/settle';
    case ReverseAuthorization = '/api/integration/reverse-authorization';
    case CheckStatus = '/api/integration/check-status';

    /**
     * The ordered request fields for this endpoint.
     *
     * @return array<int, array{name: string, signed: bool}>
     */
    public function fields(): array
    {
        return match ($this) {
            self::InvoiceCreate => [
                ['name' => 'first_name', 'signed' => true],
                ['name' => 'last_name', 'signed' => true],
                ['name' => 'email', 'signed' => true],
                ['name' => 'order_title', 'signed' => true],
                ['name' => 'order_amount', 'signed' => true],
                ['name' => 'address', 'signed' => true],
                ['name' => 'city', 'signed' => true],
                ['name' => 'country', 'signed' => true],
                ['name' => 'state', 'signed' => true],
                ['name' => 'currency', 'signed' => true],
                ['name' => 'redirection_url', 'signed' => true],
                ['name' => 'webhook_url', 'signed' => true],
                ['name' => 'order_details', 'signed' => true],
                ['name' => 'payment_mode', 'signed' => false],
                ['name' => 'iframe', 'signed' => false],
            ],
            self::Void, self::ReverseAuthorization, self::CheckStatus => [
                ['name' => 'invoice_id', 'signed' => true],
            ],
            self::Refund, self::Settle => [
                ['name' => 'invoice_id', 'signed' => true],
                ['name' => 'amount', 'signed' => true],
            ],
        };
    }
}
