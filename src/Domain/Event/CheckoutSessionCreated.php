<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Emitted after a checkout session is created for the customer to complete payment.
 */
final readonly class CheckoutSessionCreated implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that created the session.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  Money  $money  Amount and currency of the checkout.
     * @param  CheckoutSession  $session  The created session (redirect URL, reference, …).
     */
    public function __construct(
        public GatewayName $gateway,
        public ?string $orderReference,
        public Money $money,
        public CheckoutSession $session,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
