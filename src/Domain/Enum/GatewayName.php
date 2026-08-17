<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Canonical identifier for each payment gateway the SDK can drive.
 *
 * The string backing value is the stable machine key used in configuration and
 * factory lookups, while the enum case is what the PaymentGatewayFactory matches
 * on to construct the concrete gateway driver.
 */
enum GatewayName: string
{
    case CybersourceUnifiedCheckout = 'cybersource_uc';
    case Fawry = 'fawry';
    case Paymob = 'paymob';
    case Paylink = 'paylink';
    case Paytabs = 'paytabs';
    case PayPal = 'paypal';
    case Mpgs = 'mpgs';
    case AuthorizeNet = 'authorize_net';
    case Airwallex = 'airwallex';

    /**
     * Human-readable display name for the gateway.
     *
     * @return string The label suitable for showing in UIs and logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::CybersourceUnifiedCheckout => 'CyberSource Unified Checkout',
            self::Fawry => 'Fawry',
            self::Paymob => 'Paymob',
            self::Paylink => 'PayLink',
            self::Paytabs => 'PayTabs',
            self::PayPal => 'PayPal',
            self::Mpgs => 'Mastercard Payment Gateway Services',
            self::AuthorizeNet => 'Authorize.Net',
            self::Airwallex => 'Airwallex',
        };
    }
}
