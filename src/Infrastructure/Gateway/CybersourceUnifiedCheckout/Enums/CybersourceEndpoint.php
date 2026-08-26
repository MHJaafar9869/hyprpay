<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums;

/**
 * CyberSource REST API resource paths used by the Unified Checkout gateway.
 *
 * Each case is the URL path (relative to the credential host) for a CyberSource
 * service — Unified Checkout capture contexts, payment processing, token
 * management (TMS), payer authentication (risk), and transaction search (TSS).
 * Cases with an `{id}` placeholder are resolved via path().
 */
enum CybersourceEndpoint: string
{
    case CaptureContexts = '/up/v1/capture-contexts';
    case Payments = '/pts/v2/payments';
    case Captures = '/pts/v2/payments/{id}/captures';
    case Refunds = '/pts/v2/payments/{id}/refunds';
    case Voids = '/pts/v2/payments/{id}/voids';
    case Reversals = '/pts/v2/payments/{id}/reversals';
    case CurrencyConversion = '/vas/v1/currencyconversion';
    case AuthenticationSetups = '/risk/v1/authentication-setups';
    case Authentications = '/risk/v1/authentications';
    case AuthenticationResults = '/risk/v1/authentication-results';
    case InstrumentIdentifiers = '/tms/v1/instrumentidentifiers';
    case Customers = '/tms/v2/customers';
    case CustomerPaymentInstruments = '/tms/v2/customers/{id}/payment-instruments';
    case TransactionDetails = '/tss/v2/transactions/{id}';
    case TransactionSearch = '/tss/v2/searches';

    /**
     * Returns the concrete request path, substituting the `{id}` placeholder with
     * the given resource identifier (payment/customer id). Endpoints without a
     * placeholder ignore the argument.
     *
     * @param  string  $id  Resource id to interpolate into the path template.
     */
    public function path(string $id = ''): string
    {
        return str_replace('{id}', $id, $this->value);
    }
}
