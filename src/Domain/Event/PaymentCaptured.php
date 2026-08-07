<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Emitted after a capture of a previously authorized payment completes.
 */
final readonly class PaymentCaptured implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that ran the capture.
     * @param  string  $transactionId  The authorization being captured.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  Money  $money  Amount and currency captured.
     * @param  PaymentResult  $result  Normalized outcome of the capture.
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
