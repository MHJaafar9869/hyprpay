<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Emitted after a refund of a settled payment completes.
 */
final readonly class PaymentRefunded implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that ran the refund.
     * @param  string  $transactionId  The captured transaction being refunded.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  Money  $money  Amount and currency refunded.
     * @param  RefundResult  $result  Normalized outcome of the refund.
     */
    public function __construct(
        public GatewayName $gateway,
        public string $transactionId,
        public ?string $orderReference,
        public Money $money,
        public RefundResult $result,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
