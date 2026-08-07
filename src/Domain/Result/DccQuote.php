<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Result DTO describing a Dynamic Currency Conversion (DCC) rate quote.
 *
 * Returned by the gateway's rate-inquiry operation. When DCC is offered, it carries the
 * quoted rate id, exchange rate, and the amount converted into the cardholder's billing
 * currency. Pass the quote back into a {@see ChargeRequest}
 * (with `money` set to {@see $convertedAmount}) so the same rate id is echoed on the
 * authorization, capture, and refund — the exact rate the cardholder was quoted.
 */
final readonly class DccQuote
{
    /**
     * @param  bool  $offered  Whether the gateway offered DCC for this card and amount (a rate was quoted)
     * @param  string|null  $id  Quoted rate id, echoed as the currency-conversion id on the DCC auth/capture/refund
     * @param  string|null  $exchangeRate  Quoted exchange rate as an exact decimal string (e.g. "48.00")
     * @param  Money|null  $originalAmount  The amount in the merchant's currency, as sent
     * @param  Money|null  $convertedAmount  The amount in the cardholder's billing currency at the quoted rate
     * @param  string|null  $exchangeRateTimeStamp  Gateway timestamp identifying when the rate was quoted
     * @param  array<string, mixed>  $raw  Raw decoded gateway response
     */
    public function __construct(
        public bool $offered,
        public ?string $id = null,
        public ?string $exchangeRate = null,
        public ?Money $originalAmount = null,
        public ?Money $convertedAmount = null,
        public ?string $exchangeRateTimeStamp = null,
        public array $raw = [],
    ) {}
}
