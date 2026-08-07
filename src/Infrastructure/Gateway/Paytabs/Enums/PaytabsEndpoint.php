<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs\Enums;

/**
 * The PayTabs PT2 REST endpoints the driver calls.
 *
 * Each case's backing value is the request path, appended to the merchant's
 * region-specific host (e.g. secure.paytabs.sa, secure-egypt.paytabs.com,
 * secure.paytabs.com) resolved from the credentials. PayTabs uses the same host for
 * the sandbox and production environments — a test transaction is driven by a test
 * profile id and server key, not a different URL — so no test-mode switch is needed.
 * Payments, captures, refunds, voids, hosted/invoice/managed-form checkouts, Own Form
 * (payment_token) charges, and token-based (stored credential) charges all post to
 * {@see Request}, distinguished by the body's `tran_type` and fields; {@see Query}
 * looks a transaction up by its `tran_ref`; {@see LinkCreate} creates a reusable
 * PayLink; {@see TokenDelete} revokes a saved card token.
 */
enum PaytabsEndpoint: string
{
    case Request = '/payment/request';
    case Query = '/payment/query';
    case LinkCreate = '/payment/link/create';
    case TokenDelete = '/payment/token/delete';
}
