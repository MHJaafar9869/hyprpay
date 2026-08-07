<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\PaymentResult;

/**
 * Emitted after an authorized-but-uncaptured payment is voided.
 */
final readonly class PaymentVoided implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that ran the void.
     * @param  string  $transactionId  The transaction being voided.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  PaymentResult  $result  Normalized outcome of the void.
     */
    public function __construct(
        public GatewayName $gateway,
        public string $transactionId,
        public ?string $orderReference,
        public PaymentResult $result,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
