<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Emitted after a 3-D Secure enrolment check.
 *
 * Enrolment is where a checkout either sails through frictionless or is sent to a step-up
 * challenge, and it is the most common place for one to be abandoned. Recording it gives the
 * feed the drop-off that a charge-only view cannot show.
 */
final readonly class PayerAuthenticationEnrolled implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that ran the enrolment.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  Money  $money  The amount the enrolment was checked against.
     * @param  PayerAuthResult  $result  The enrolment outcome; a step-up URL means a challenge is required.
     */
    public function __construct(
        public GatewayName $gateway,
        public ?string $orderReference,
        public Money $money,
        public PayerAuthResult $result,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
