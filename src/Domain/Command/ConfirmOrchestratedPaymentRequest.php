<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for confirming a Unified Checkout v1 orchestrated (autoProcessing) payment.
 *
 * In the orchestrated flow the widget runs Decision Manager, 3-D Secure, authorization,
 * and TMS tokenization client-side and resolves with a signed completed-payment result
 * JWT. This command carries that result JWT together with the capture-context JWT it was
 * minted under (the source of the verification public key) and the values the result must
 * match — the expected amount/currency and, optionally, the order reference and issuer.
 * The gateway verifies the JWT's signature and validates it against these before trusting
 * the outcome; no server-side authorization or transaction-search call is made.
 */
final readonly class ConfirmOrchestratedPaymentRequest
{
    /**
     * @param  string  $resultJwt  The signed completed-payment result JWT returned by checkout.mount().
     * @param  string  $captureContextJwt  The capture-context JWT the session was created with; carries the embedded verification key.
     * @param  Money  $expectedMoney  The amount and currency the result must match (rejected on mismatch).
     * @param  string|null  $orderReference  Optional merchant order reference the result must match when supplied.
     * @param  string|null  $expectedIssuer  Optional issuer (iss) claim the result must carry when supplied.
     * @param  int  $leewaySeconds  Clock-skew allowance in seconds applied to exp/iat/nbf validation.
     */
    public function __construct(
        public string $resultJwt,
        public string $captureContextJwt,
        public Money $expectedMoney,
        public ?string $orderReference = null,
        public ?string $expectedIssuer = null,
        public int $leewaySeconds = 60,
    ) {}
}
