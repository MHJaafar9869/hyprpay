<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Event;

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Emitted after a dynamic-currency-conversion rate is requested for a card.
 *
 * A DCC quote is a billable gateway round-trip that shapes what the cardholder is offered, so
 * it belongs in the activity feed even though it settles nothing: a checkout that quotes and
 * then never charges is exactly the gap an operator wants to see. The quote may report that no
 * conversion was offered, which is a normal outcome rather than a failure.
 */
final readonly class DccRateQuoted implements PaymentEvent
{
    /**
     * @param  GatewayName  $gateway  The gateway that quoted the rate.
     * @param  string|null  $orderReference  Merchant order reference for correlation.
     * @param  Money  $money  The original amount the quote was requested for.
     * @param  DccQuote  $quote  The returned quote; `offered` is false when the card is not eligible.
     */
    public function __construct(
        public GatewayName $gateway,
        public ?string $orderReference,
        public Money $money,
        public DccQuote $quote,
    ) {}

    public function gateway(): GatewayName
    {
        return $this->gateway;
    }
}
