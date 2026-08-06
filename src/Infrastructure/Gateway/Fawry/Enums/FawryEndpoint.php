<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry\Enums;

/**
 * The FawryPay REST endpoints the driver calls, with environment-aware URLs.
 *
 * Unlike CyberSource, FawryPay uses different hosts (and, for hosted checkout, a
 * different host again) between its sandbox and production environments, so each
 * case resolves to a full URL rather than a path appended to a single base host.
 */
enum FawryEndpoint
{
    case HostedInit;
    case Charge;
    case Refund;
    case PaymentCapture;
    case PaymentCancel;
    case StatusV2;

    /**
     * Resolve the full endpoint URL for the given environment.
     *
     * @param  bool  $testMode  True for the FawryPay staging environment, false for production.
     * @return string The absolute URL to send the request to.
     */
    public function url(bool $testMode): string
    {
        return match ($this) {
            self::HostedInit => $testMode
                ? 'https://atfawry.fawrystaging.com/fawrypay-api/api/payments/init'
                : 'https://atfawry.com/fawrypay-api/api/payments/init',
            self::Charge => $testMode
                ? 'https://atfawry.fawrystaging.com/ECommerceWeb/Fawry/payments/charge'
                : 'https://www.atfawry.com/ECommerceWeb/Fawry/payments/charge',
            self::Refund => $testMode
                ? 'https://atfawry.fawrystaging.com/ECommerceWeb/Fawry/payments/refund'
                : 'https://www.atfawry.com/ECommerceWeb/Fawry/payments/refund',
            self::PaymentCapture => $testMode
                ? 'https://atfawry.fawrystaging.com/ECommerceWeb/api/payment/capture'
                : 'https://www.atfawry.com/ECommerceWeb/api/payment/capture',
            self::PaymentCancel => $testMode
                ? 'https://atfawry.fawrystaging.com/ECommerceWeb/api/payment/cancel'
                : 'https://www.atfawry.com/ECommerceWeb/api/payment/cancel',
            self::StatusV2 => $testMode
                ? 'https://atfawry.fawrystaging.com/ECommerceWeb/Fawry/payments/status/v2'
                : 'https://www.atfawry.com/ECommerceWeb/Fawry/payments/status/v2',
        };
    }
}
