<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Emitted after a charge completes; inspect the result's success/status for the outcome.
 */
final readonly class PaymentCharged implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that ran the charge.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  Money  $money  Amount and currency charged.
     * @param  PaymentResult  $result  Normalized outcome of the charge.
     */
    public function __construct(
        public GatewayName $gateway,
        public ?string $orderReference,
        public Money $money,
        public PaymentResult $result,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
