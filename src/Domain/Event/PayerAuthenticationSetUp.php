<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\PayerAuthSetupResult;

/**
 * Emitted after 3-D Secure setup, the first leg of payer authentication.
 *
 * Setup returns the reference and device-data-collection URL the browser needs before
 * enrolment can run. Recording it means a checkout that stalls during 3-D Secure still leaves
 * a trail, instead of the feed jumping straight from nothing to a charge that never happened.
 */
final readonly class PayerAuthenticationSetUp implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that ran the setup.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  PayerAuthSetupResult  $result  The setup outcome and its reference id.
     */
    public function __construct(
        public GatewayName $gateway,
        public ?string $orderReference,
        public PayerAuthSetupResult $result,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
