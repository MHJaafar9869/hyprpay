<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for enrolling a card into 3-D Secure payer authentication.
 *
 * Passed to the gateway's enroll operation to begin the 3DS check for the card
 * captured by the widget; the resulting {@see PayerAuthResult} indicates whether a
 * challenge (step-up) is required.
 */
final readonly class PayerAuthEnrollRequest
{
    /**
     * @param  string  $transientToken  One-time token from the Unified Checkout widget representing the card
     * @param  Money  $money  Amount and currency being authenticated
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     * @param  string|null  $returnUrl  URL the issuer redirects back to after a 3DS challenge
     * @param  string|null  $referenceId  Optional device-data-collection reference id from setup
     * @param  BillingAddress|null  $billTo  Optional billing address for the payer
     */
    public function __construct(
        public string $transientToken,
        public Money $money,
        public ?string $orderReference = null,
        public ?string $returnUrl = null,
        public ?string $referenceId = null,
        public ?BillingAddress $billTo = null,
    ) {}
}
