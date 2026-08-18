<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Airwallex\Payloads;

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Builds the Airwallex "Online Payments" request bodies for each operation the driver performs.
 *
 * A new payment is a PaymentIntent ({@see createIntent()}): its `amount` is an exact major-unit
 * decimal, `merchant_order_id`/`request_id` carry the merchant order reference for reconciliation
 * and idempotency, and `payment_method_options.card.capture_method` selects immediate capture or an
 * authorization hold. Follow-ons build the capture ({@see capture()}) and refund ({@see refund()})
 * bodies, while {@see storedCredentialIntent()}/{@see confirmConsent()} drive the create-then-confirm
 * flow that charges a saved PaymentConsent. Amounts are numeric (major units) and null fields are
 * dropped so a body only contains what the caller supplied.
 */
final class AirwallexPayload
{
    /**
     * Build a create-PaymentIntent body for a new checkout.
     *
     * `request_id` and `merchant_order_id` are both the merchant order reference, so a retried
     * create is deduplicated by Airwallex. The `paymentMethod` selector sets the capture method —
     * `authorize` places an authorization hold (`manual`), anything else captures on confirmation
     * (`automatic`). The return URL, descriptor, customer id, and metadata are attached only when
     * supplied.
     *
     * @return array<string, mixed>
     */
    public static function createIntent(CheckoutSessionRequest $request): array
    {
        $reference = Value::string($request->orderReference);

        return self::withoutNulls([
            'request_id' => $reference,
            'merchant_order_id' => $reference,
            'amount' => self::amount($request->money),
            'currency' => $request->money->currency,
            'return_url' => $request->returnUrl,
            'descriptor' => $request->description,
            'customer_id' => $request->customer?->reference,
            'metadata' => self::metadata($request),
            'payment_method_options' => [
                'card' => ['capture_method' => self::captureMethod($request->paymentMethod)],
            ],
        ]);
    }

    /**
     * Build the create-PaymentIntent body for a server-side charge of a tokenized card.
     *
     * `request_id`/`merchant_order_id` carry the caller's reference so a retried create is
     * deduplicated. `capture` selects immediate capture (`automatic`) or an authorization hold
     * (`manual`); the return URL and customer id are attached only when supplied.
     *
     * @return array<string, mixed>
     */
    public static function chargeIntent(ChargeRequest $request): array
    {
        $reference = self::requestId($request->idempotencyKey, $request->orderReference, $request->transientToken);

        return self::withoutNulls([
            'request_id' => $reference,
            'merchant_order_id' => Value::nullableString($request->orderReference) ?? $reference,
            'amount' => self::amount($request->money),
            'currency' => $request->money->currency,
            'customer_id' => $request->customer?->reference,
            'payment_method_options' => [
                'card' => ['capture_method' => $request->capture ? 'automatic' : 'manual'],
            ],
        ]);
    }

    /**
     * Build the confirm body that charges a PaymentIntent with a tokenized PaymentMethod.
     *
     * The charge's transient token is an Airwallex PaymentMethod id (created client-side by
     * Airwallex.js), referenced here as `payment_method.id`. The `-confirm` suffix keeps the
     * confirm idempotency key distinct from the create call's.
     *
     * @return array<string, mixed>
     */
    public static function confirmMethod(ChargeRequest $request): array
    {
        $reference = self::requestId($request->idempotencyKey, $request->orderReference, $request->transientToken);

        return [
            'request_id' => $reference.'-confirm',
            'payment_method' => ['id' => $request->transientToken],
        ];
    }

    /**
     * Build the cancel body for a void or authorization reversal.
     *
     * @return array<string, mixed>
     */
    public static function cancel(string $requestId, ?string $reason): array
    {
        return self::withoutNulls([
            'request_id' => $requestId,
            'cancellation_reason' => $reason,
        ]);
    }

    /**
     * Build the create-PaymentConsent body that begins vaulting a card for reuse.
     *
     * A consent is a card-on-file mandate tied to a customer; {@see verifyConsent()} then attaches
     * and validates the card. The consent is merchant-triggerable and unscheduled so it can back a
     * later {@see StoredCredentialChargeRequest}.
     *
     * @return array<string, mixed>
     */
    public static function createConsent(TokenizeInstrumentRequest $request): array
    {
        return [
            'request_id' => Value::string($request->customerReference),
            'customer_id' => Value::string($request->customerReference),
            'next_triggered_by' => 'merchant',
            'merchant_trigger_reason' => 'unscheduled',
        ];
    }

    /**
     * Build the verify-PaymentConsent body that attaches and validates the card on a consent.
     *
     * The card is referenced by its Airwallex PaymentMethod id (the request's transient token) when
     * present, otherwise built from the raw card fields. `verification_options.card.currency` scopes
     * the verification.
     *
     * @return array<string, mixed>
     */
    public static function verifyConsent(TokenizeInstrumentRequest $request, string $currency): array
    {
        $paymentMethod = Value::nullableString($request->transientToken) !== null
            ? ['id' => $request->transientToken]
            : ['type' => 'card', 'card' => self::card($request)];

        return [
            'request_id' => Value::string($request->customerReference).'-verify',
            'payment_method' => $paymentMethod,
            'verification_options' => ['card' => ['currency' => $currency]],
        ];
    }

    /**
     * Build the create-PaymentIntent body that begins a stored-credential (saved-card) charge.
     *
     * The intent captures automatically on confirmation; {@see confirmConsent()} then confirms it
     * against the saved PaymentConsent. The customer id links the intent to the vault customer when
     * known.
     *
     * @return array<string, mixed>
     */
    public static function storedCredentialIntent(StoredCredentialChargeRequest $request): array
    {
        $reference = Value::string($request->idempotencyKey ?? $request->orderReference);

        return self::withoutNulls([
            'request_id' => $reference,
            'merchant_order_id' => Value::nullableString($request->orderReference) ?? $reference,
            'amount' => self::amount($request->money),
            'currency' => $request->money->currency,
            'customer_id' => $request->customerId,
            'payment_method_options' => [
                'card' => ['capture_method' => 'automatic'],
            ],
        ]);
    }

    /**
     * Build the confirm body that charges a saved PaymentConsent against a created intent.
     *
     * @return array<string, mixed>
     */
    public static function confirmConsent(StoredCredentialChargeRequest $request): array
    {
        $reference = Value::string($request->idempotencyKey ?? $request->orderReference);

        return self::withoutNulls([
            'request_id' => filled($reference) ? $reference.'-confirm' : null,
            'payment_consent_id' => $request->paymentInstrumentId,
        ]);
    }

    /**
     * Build the body that captures funds on an authorized (manual-capture) PaymentIntent.
     *
     * A smaller amount performs a partial capture.
     *
     * @return array<string, mixed>
     */
    public static function capture(CaptureRequest $request): array
    {
        return self::withoutNulls([
            'request_id' => self::requestId($request->idempotencyKey, $request->orderReference, $request->transactionId),
            'amount' => self::amount($request->money),
        ]);
    }

    /**
     * Build the create-Refund body that refunds all or part of a captured PaymentIntent.
     *
     * The amount is the exact major-unit decimal to return (a smaller amount performs a partial
     * refund); `reason` is attached only when supplied.
     *
     * @return array<string, mixed>
     */
    public static function refund(RefundRequest $request): array
    {
        return self::withoutNulls([
            'request_id' => self::requestId($request->idempotencyKey, $request->orderReference, $request->transactionId),
            'payment_intent_id' => $request->transactionId,
            'amount' => self::amount($request->money),
            'reason' => $request->reason,
        ]);
    }

    /**
     * Resolve the idempotency `request_id`, preferring the explicit key, then the order reference,
     * then the transaction id so a retried write is always deduplicated by Airwallex.
     */
    private static function requestId(?string $idempotencyKey, ?string $orderReference, string $transactionId): string
    {
        return Value::nullableString($idempotencyKey)
            ?? Value::nullableString($orderReference)
            ?? $transactionId;
    }

    /**
     * Build the Airwallex `card` object from the tokenize request's raw card fields.
     *
     * @return array<string, mixed>
     */
    private static function card(TokenizeInstrumentRequest $request): array
    {
        return self::withoutNulls([
            'number' => $request->cardNumber,
            'expiry_month' => $request->expirationMonth,
            'expiry_year' => $request->expirationYear,
            'billing' => self::billing($request->billTo),
        ]);
    }

    /**
     * Map the SDK billing address onto Airwallex's card `billing` shape, or null when absent.
     *
     * @return array<string, mixed>|null
     */
    private static function billing(?BillingAddress $billTo): ?array
    {
        if (! $billTo instanceof BillingAddress) {
            return null;
        }

        $address = array_filter([
            'street' => $billTo->address1,
            'city' => $billTo->locality,
            'state' => $billTo->administrativeArea,
            'postcode' => $billTo->postalCode,
            'country_code' => $billTo->country,
        ], static fn (?string $value): bool => $value !== null);

        $billing = array_filter([
            'first_name' => $billTo->firstName,
            'last_name' => $billTo->lastName,
            'address' => $address === [] ? null : $address,
        ], static fn ($value): bool => $value !== null);

        return $billing === [] ? null : $billing;
    }

    /**
     * Render a Money as an Airwallex amount — an exact major-unit decimal number.
     *
     * The decimal string is parsed to a float so it serialises as a JSON number (100.00 → 100,
     * 12.34 → 12.34) as Airwallex expects, without ever rounding the stored minor amount.
     */
    private static function amount(Money $money): float
    {
        return (float) $money->toDecimalString();
    }

    /**
     * Resolve the card capture method: an authorization hold for `authorize`, else immediate capture.
     */
    private static function captureMethod(?string $paymentMethod): string
    {
        return strtolower((string) $paymentMethod) === 'authorize' ? 'manual' : 'automatic';
    }

    /**
     * Extract a non-empty metadata object from the checkout options, or null when none was supplied.
     *
     * Airwallex expects metadata as a JSON object, so an empty array is dropped rather than sent as `[]`.
     *
     * @return array<string, mixed>|null
     */
    private static function metadata(CheckoutSessionRequest $request): ?array
    {
        $metadata = Value::array($request->optionsArray()['metadata'] ?? null);

        return $metadata === [] ? null : $metadata;
    }

    /**
     * Drop null-valued fields while preserving zero/false/empty-string values.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $body): array
    {
        return array_filter($body, static fn ($value): bool => $value !== null);
    }
}
