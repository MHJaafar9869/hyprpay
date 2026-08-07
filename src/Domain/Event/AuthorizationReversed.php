<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Emitted after an existing authorization is reversed (its held funds released).
 */
final readonly class AuthorizationReversed implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that ran the reversal.
     * @param  string  $transactionId  The authorization being reversed.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  Money  $money  Amount and currency released.
     * @param  PaymentResult  $result  Normalized outcome of the reversal.
     */
    public function __construct(
        public GatewayName $gateway,
        public string $transactionId,
        public ?string $orderReference,
        public Money $money,
        public PaymentResult $result,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
