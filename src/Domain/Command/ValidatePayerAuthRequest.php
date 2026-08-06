<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for validating a 3-D Secure authentication after a challenge (step-up).
 *
 * Passed to the gateway's validate operation once the payer has completed the issuer
 * challenge, using the authentication transaction id from the earlier enrol step to
 * fetch the final cryptogram in a {@see PayerAuthResult}.
 */
final readonly class ValidatePayerAuthRequest
{
    /**
     * @param  string  $authenticationTransactionId  Identifier of the authentication to validate (from the enrol step)
     * @param  Money  $money  Amount and currency being authenticated
     * @param  string|null  $transientToken  Optional Unified Checkout transient token for the card
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     */
    public function __construct(
        public string $authenticationTransactionId,
        public Money $money,
        public ?string $transientToken = null,
        public ?string $orderReference = null,
    ) {}
}
