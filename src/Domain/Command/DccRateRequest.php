<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for requesting a Dynamic Currency Conversion (DCC) rate quote.
 *
 * Carries the original amount in the merchant's currency plus the card number used to
 * detect the cardholder's billing currency. Passed to the gateway's DCC rate-inquiry
 * operation, which returns a {@see DccQuote} that can
 * then be threaded into a charge/capture/refund to bill the cardholder in their currency.
 */
final readonly class DccRateRequest
{
    /**
     * @param  Money  $money  The original amount and merchant currency to convert from
     * @param  string  $cardNumber  Card number (PAN) whose BIN determines the cardholder's currency
     * @param  string|null  $orderReference  Optional merchant reference for correlation
     */
    public function __construct(
        public Money $money,
        public string $cardNumber,
        public ?string $orderReference = null,
    ) {}
}
