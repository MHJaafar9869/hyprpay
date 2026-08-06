<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns\ParsesTransientToken;

/**
 * Expose the protected transient-token parsing trait for assertions.
 */
function tokenProbe(): object
{
    return new class
    {
        use ParsesTransientToken;

        public function jti(string $token): ?string
        {
            return $this->transientTokenJti($token);
        }

        public function isWallet(string $token): bool
        {
            return $this->transientTokenIsWallet($token);
        }

        public function isApplePay(string $token): bool
        {
            return $this->transientTokenIsApplePay($token);
        }
    };
}

it('reads the jti claim from a transient token', function (): void {
    expect(tokenProbe()->jti(fakeJwt(['jti' => 'token-abc'])))->toBe('token-abc');
});

it('returns null for a malformed token', function (): void {
    expect(tokenProbe()->jti('not-a-jwt'))->toBeNull();
});

it('detects wallet tokens by their markers', function (): void {
    $googleToken = fakeJwt(['content' => ['paymentInformation' => ['scheme' => 'GOOGLE_PAY']]]);
    $appleToken = fakeJwt(['content' => ['paymentInformation' => ['scheme' => 'APPLE_PAY']]]);
    $cardToken = fakeJwt(['content' => ['paymentInformation' => ['card' => ['type' => '001']]]]);

    expect(tokenProbe()->isWallet($googleToken))->toBeTrue()
        ->and(tokenProbe()->isApplePay($appleToken))->toBeTrue()
        ->and(tokenProbe()->isWallet($cardToken))->toBeFalse();
});
