<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Emitted after a 3-D Secure step-up challenge is validated.
 *
 * The closing leg of payer authentication: the cardholder has answered the challenge and the
 * gateway has said whether the answer stands. Carries the authentication transaction id the
 * subsequent charge is correlated by — never the cryptogram or any card data.
 */
final readonly class PayerAuthenticationValidated implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that validated the challenge.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  Money  $money  The amount the validation was checked against.
     * @param  PayerAuthResult  $result  The validation outcome and its authentication transaction id.
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
