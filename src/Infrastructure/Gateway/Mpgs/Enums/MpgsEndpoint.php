<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums;

/**
 * Mastercard Payment Gateway Services (MPGS) REST resource paths.
 *
 * Each case is the path relative to the per-merchant base
 * (`/api/rest/version/{version}/merchant/{merchantId}`) that {@see MpgsClient}
 * prepends. Cases with `{placeholder}` segments — order id, transaction id,
 * session id, token id — are resolved via path().
 */
enum MpgsEndpoint: string
{
    case Session = '/session';
    case SessionById = '/session/{sessionId}';
    case Order = '/order/{orderId}';
    case Transaction = '/order/{orderId}/transaction/{transactionId}';
    case Token = '/token';
    case TokenById = '/token/{tokenId}';
    case PaymentOptionsInquiry = '/paymentOptionsInquiry';

    /**
     * Return the concrete request path, substituting each `{key}` placeholder with
     * its URL-encoded replacement value. Placeholders without a matching key are
     * left untouched.
     *
     * @param  array<string, string>  $params  Replacement values keyed by placeholder name.
     */
    public function path(array $params = []): string
    {
        $path = $this->value;

        foreach ($params as $key => $value) {
            $path = str_replace('{'.$key.'}', rawurlencode($value), $path);
        }

        return $path;
    }
}
