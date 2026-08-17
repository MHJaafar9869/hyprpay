<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Airwallex\Payloads;

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
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
            'request_id' => $reference === '' ? null : $reference.'-confirm',
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
