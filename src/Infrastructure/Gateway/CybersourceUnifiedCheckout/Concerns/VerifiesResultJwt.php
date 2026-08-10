<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hyprpay\Payments\Domain\Command\ConfirmOrchestratedPaymentRequest;
use Hyprpay\Payments\Domain\Exception\PaymentVerificationException;
use Hyprpay\Payments\Domain\Result\OrchestratedPaymentResult;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums\CybersourceTransactionStatus;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Throwable;

/**
 * Cryptographically verifies Unified Checkout v1 orchestrated-payment result JWTs.
 *
 * Closes the gap left by an unsigned (base64-only) decode: the completed-payment result
 * JWT returned by checkout.mount() is verified against the RS256 public key embedded in
 * the capture context (flx.jwk), its lifetime and issuer/order/amount are validated, and
 * the reusable TMS token is extracted for later stored-credential charges. Verification is
 * offline — no server-side authorization or transaction-search call is made.
 *
 * The composing class must also use {@see ParsesTransientToken} (for decodeJwtClaims), as
 * the gateway does.
 */
trait VerifiesResultJwt
{
    /**
     * Source the RS256 verification key from the capture context's embedded flx.jwk.
     *
     * Decodes the capture-context JWT payload and reads the JWK CyberSource embeds at
     * `flx.jwk` — the public key whose private counterpart signed the result JWT — then
     * builds a Firebase Key pinned to RS256.
     *
     * @param  string  $captureContextJwt  The capture-context JWT the session was created with.
     */
    protected function resultJwtVerificationKey(string $captureContextJwt): Key
    {
        $claims = $this->decodeJwtClaims($captureContextJwt);

        if ($claims === null) {
            throw PaymentVerificationException::missingVerificationKey('capture context could not be decoded');
        }

        $jwk = data_get($claims, 'flx.jwk');

        if (! is_array($jwk) || blank($jwk)) {
            throw PaymentVerificationException::missingVerificationKey('capture context has no flx.jwk');
        }

        try {
            /** @var array<string, mixed> $jwk */
            $key = JWK::parseKey($jwk, 'RS256');
        } catch (Throwable $throwable) {
            throw PaymentVerificationException::missingVerificationKey($throwable->getMessage());
        }

        if (! $key instanceof Key) {
            throw PaymentVerificationException::missingVerificationKey('flx.jwk is not a usable RSA key');
        }

        return $key;
    }

    /**
     * Verify the result JWT's signature and lifetime, returning its claims as an array.
     *
     * Decodes with the algorithm pinned to the key (rejecting alg:none / algorithm
     * confusion) and applies the caller's clock-skew leeway to exp/iat/nbf. Any failure is
     * surfaced as a {@see PaymentVerificationException} so the result is never trusted.
     *
     * @param  string  $resultJwt  The signed completed-payment result JWT.
     * @param  Key  $key  The RS256 verification key sourced from the capture context.
     * @param  int  $leewaySeconds  Clock-skew allowance in seconds.
     * @return array<string, mixed>
     */
    protected function verifyResultJwtClaims(string $resultJwt, Key $key, int $leewaySeconds): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = max(0, $leewaySeconds);

        try {
            $decoded = JWT::decode($resultJwt, $key);
        } catch (ExpiredException|BeforeValidException $throwable) {
            throw PaymentVerificationException::expired($throwable->getMessage());
        } catch (Throwable $throwable) {
            throw PaymentVerificationException::invalidSignature($throwable->getMessage());
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        return Value::array(json_decode((string) json_encode($decoded), true));
    }

    /**
     * Reject the result JWT unless its issuer, order reference, and amount match the request.
     *
     * @param  array<string, mixed>  $claims  Verified result JWT claims.
     * @param  ConfirmOrchestratedPaymentRequest  $request  The confirmation request to validate against.
     */
    protected function assertOrchestratedResultMatches(array $claims, ConfirmOrchestratedPaymentRequest $request): void
    {
        $this->assertOrchestratedIssuer($claims, $request->expectedIssuer);
        $this->assertOrchestratedOrderReference($claims, $request->orderReference);
        $this->assertOrchestratedAmount($claims, $request->expectedMoney);
    }

    /**
     * Map verified result JWT claims into an OrchestratedPaymentResult.
     *
     * Reads the transaction id and status from the top level, and the amount, order
     * reference, TMS token identifiers, network transaction id, and card metadata from the
     * `details` envelope (falling back to the top level). Wallet payments carry no reusable
     * credential, so their token identifiers are dropped and isWallet is set.
     *
     * @param  array<string, mixed>  $claims  Verified result JWT claims.
     */
    protected function orchestratedPaymentResult(array $claims): OrchestratedPaymentResult
    {
        $status = CybersourceTransactionStatus::toPaymentStatusOrFailed(
            Value::nullableString($claims['status'] ?? data_get($claims, 'details.status')),
        );

        $isWallet = $this->orchestratedResultIsWallet($claims);

        return new OrchestratedPaymentResult(
            success: $status->isSuccessful(),
            status: $status,
            transactionId: Value::nullableString($claims['id'] ?? data_get($claims, 'details.id')),
            orderReference: $this->orchestratedClaim($claims, 'clientReferenceInformation.code'),
            networkTransactionId: $this->firstOrchestratedClaim($claims, [
                'processorInformation.networkTransactionId',
                'processorInformation.transactionId',
            ]),
            isWallet: $isWallet,
            instrumentIdentifierId: $isWallet ? null : $this->orchestratedClaim($claims, 'tokenInformation.instrumentIdentifier.id'),
            paymentInstrumentId: $isWallet ? null : $this->orchestratedClaim($claims, 'tokenInformation.paymentInstrument.id'),
            customerId: $isWallet ? null : $this->firstOrchestratedClaim($claims, [
                'tokenInformation.customer.id',
                'tokenInformation.customer.customerId',
            ]),
            cardBrand: $this->orchestratedCardBrand($claims),
            cardLast4: $this->firstOrchestratedClaim($claims, [
                'paymentInformation.tokenizedCard.suffix',
                'paymentInformation.card.suffix',
                'paymentAccountInformation.card.suffix',
            ]),
            cardExpiryMonth: $this->firstOrchestratedClaim($claims, [
                'paymentInformation.tokenizedCard.expirationMonth',
                'paymentInformation.card.expirationMonth',
            ]),
            cardExpiryYear: $this->firstOrchestratedClaim($claims, [
                'paymentInformation.tokenizedCard.expirationYear',
                'paymentInformation.card.expirationYear',
            ]),
            raw: $claims,
        );
    }

    /**
     * Reject the result unless it carries the expected issuer, when one was required.
     *
     * @param  array<string, mixed>  $claims  Verified result JWT claims.
     * @param  string|null  $expectedIssuer  Issuer the caller required, or null to skip the check.
     */
    private function assertOrchestratedIssuer(array $claims, ?string $expectedIssuer): void
    {
        if ($expectedIssuer === null) {
            return;
        }

        $actual = Value::nullableString($claims['iss'] ?? null);

        if ($actual !== $expectedIssuer) {
            throw PaymentVerificationException::issuerMismatch($expectedIssuer, Value::string($actual));
        }
    }

    /**
     * Reject the result unless it carries the expected order reference, when one was required.
     *
     * @param  array<string, mixed>  $claims  Verified result JWT claims.
     * @param  string|null  $expected  Order reference the caller required, or null to skip the check.
     */
    private function assertOrchestratedOrderReference(array $claims, ?string $expected): void
    {
        if ($expected === null) {
            return;
        }

        $actual = $this->orchestratedClaim($claims, 'clientReferenceInformation.code');

        if ($actual !== $expected) {
            throw PaymentVerificationException::orderReferenceMismatch($expected, Value::string($actual));
        }
    }

    /**
     * Reject the result unless its amount and currency equal the expected Money.
     *
     * @param  array<string, mixed>  $claims  Verified result JWT claims.
     * @param  Money  $expected  The amount and currency the result must match.
     */
    private function assertOrchestratedAmount(array $claims, Money $expected): void
    {
        $amount = $this->orchestratedClaim($claims, 'orderInformation.amountDetails.totalAmount');
        $currency = $this->orchestratedClaim($claims, 'orderInformation.amountDetails.currency');

        if ($amount === null || $currency === null) {
            throw PaymentVerificationException::amountMismatch(
                $expected->toDecimalString().' '.$expected->currency,
                'missing amount details',
            );
        }

        $actual = Money::fromDecimalString($amount, $currency);

        if ($actual->currency !== $expected->currency) {
            throw PaymentVerificationException::currencyMismatch($expected->currency, $actual->currency);
        }

        if (! $this->sameMonetaryValue($actual, $expected)) {
            throw PaymentVerificationException::amountMismatch($expected->toDecimalString(), $amount);
        }
    }

    /**
     * Whether the verified result was paid with an Apple Pay / Google Pay wallet.
     *
     * Wallet payments yield no reusable instrument identifier, so callers must not schedule
     * installments against them.
     *
     * @param  array<string, mixed>  $claims  Verified result JWT claims.
     */
    private function orchestratedResultIsWallet(array $claims): bool
    {
        $type = strtoupper((string) ($this->firstOrchestratedClaim($claims, [
            'paymentInformation.paymentType.type',
            'paymentInformation.paymentType.name',
        ]) ?? ''));

        return in_array($type, ['APPLEPAY', 'APPLE_PAY', 'GOOGLEPAY', 'GOOGLE_PAY'], true);
    }

    /**
     * Human-readable card brand from the result's card type code, or null when absent.
     *
     * @param  array<string, mixed>  $claims  Verified result JWT claims.
     */
    private function orchestratedCardBrand(array $claims): ?string
    {
        $type = $this->firstOrchestratedClaim($claims, [
            'paymentInformation.tokenizedCard.type',
            'paymentInformation.card.type',
            'paymentAccountInformation.card.type',
        ]);

        if ($type === null) {
            return null;
        }

        return match ($type) {
            '001' => 'visa',
            '002' => 'mastercard',
            '003' => 'amex',
            '004' => 'discover',
            '005' => 'diners',
            '007' => 'jcb',
            '024', '042' => 'maestro',
            '062' => 'visa_electron',
            default => $type,
        };
    }

    /**
     * The first non-blank value among the given claim paths (each tried details-first).
     *
     * @param  array<string, mixed>  $claims  Verified result JWT claims.
     * @param  list<string>  $paths  Relative claim paths to try in order.
     */
    private function firstOrchestratedClaim(array $claims, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->orchestratedClaim($claims, $path);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Read a claim as a string, preferring the `details` envelope then the top level.
     *
     * The orchestrated result JWT nests the payment response under a `details` claim; the
     * same relative paths also appear at the top level in some shapes, so both are tried.
     *
     * @param  array<string, mixed>  $claims  Verified result JWT claims.
     * @param  string  $path  Relative claim path (e.g. tokenInformation.instrumentIdentifier.id).
     */
    private function orchestratedClaim(array $claims, string $path): ?string
    {
        $value = data_get($claims, 'details.'.$path);

        if (blank($value)) {
            $value = data_get($claims, $path);
        }

        return Value::nullableString($value);
    }

    /**
     * Whether two Money values represent the same monetary amount, tolerant of scale.
     *
     * Normalizes both to the larger scale before comparing minor units, so 100.0 and
     * 100.00 of the same currency compare equal.
     */
    private function sameMonetaryValue(Money $a, Money $b): bool
    {
        $scale = max($a->scale, $b->scale);

        $normalizedA = $a->minorAmount * (10 ** ($scale - $a->scale));
        $normalizedB = $b->minorAmount * (10 ** ($scale - $b->scale));

        return $normalizedA === $normalizedB;
    }
}
