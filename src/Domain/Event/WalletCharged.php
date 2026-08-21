<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\WalletType;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Emitted after a digital-wallet charge (Apple Pay / Google Pay) completes.
 */
final readonly class WalletCharged implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that ran the charge.
     * @param  WalletType  $wallet  The wallet whose token was charged.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  Money  $money  Amount and currency charged.
     * @param  PaymentResult  $result  Normalized outcome of the charge.
     */
    public function __construct(
        public GatewayName $gateway,
        public WalletType $wallet,
        public ?string $orderReference,
        public Money $money,
        public PaymentResult $result,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
