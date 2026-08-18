<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Airwallex;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Airwallex\Enums\AirwallexEndpoint;
use Hyprpay\Payments\Infrastructure\Gateway\Airwallex\Enums\AirwallexIntentStatus;
use Hyprpay\Payments\Infrastructure\Gateway\Airwallex\Payloads\AirwallexPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Airwallex "Online Payments" (card) payment gateway adapter.
 *
 * Drives Airwallex's PaymentIntent flow. {@see createCheckoutSession()} creates a PaymentIntent and
 * returns its id together with the `client_secret` that initialises Airwallex's client-side
 * (Elements / drop-in) checkout, which collects the card and confirms the intent in the browser.
 * {@see charge()} is the server-side alternative: it creates a PaymentIntent and confirms it against
 * a client-tokenized PaymentMethod id. The follow-ons act on the resulting intent — {@see capture()}
 * settles a manual-capture (authorization-hold) intent, {@see void()} and {@see reverseAuthorization()}
 * cancel an uncaptured one, {@see refund()} returns funds on a captured one, and {@see getTransaction()}
 * / {@see searchTransaction()} read intents back for reconciliation. {@see vaultInstrument()} vaults a
 * card into a PaymentConsent and {@see chargeStoredCredential()} charges it. {@see verifyWebhook()}
 * validates a notification by recomputing its HMAC-SHA256 signature over the timestamp and raw body.
 * Dynamic Currency Conversion and standalone 3-D Secure payer-auth (enroll/validate) are not part of
 * this driver — Airwallex offers no DCC quote and runs 3-D Secure inline during confirmation — so they
 * inherit {@see AbstractPaymentGateway}'s unsupported behaviour.
 *
 * Requests are built deterministically from the caller's inputs, and write operations carry a
 * `request_id` derived from the caller's idempotency key or order reference, so a retried request is
 * deduplicated by Airwallex. Amounts are sent as exact major-unit decimals.
 */
final class AirwallexGateway extends AbstractPaymentGateway
{
    private readonly AirwallexClient $client;

    /**
     * Construct the gateway, wiring an AirwallexClient from the credentials and HTTP port.
     */
    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);

        $this->client = new AirwallexClient($http, $credentials);
    }

    /**
     * Identify this driver as the Airwallex gateway.
     */
    public function name(): GatewayName
    {
        return GatewayName::Airwallex;
    }

    /**
     * Create a PaymentIntent and return the id plus the client secret that drives the checkout.
     *
     * The returned session's `reference` is the PaymentIntent id and `jwt` is the `client_secret`
     * handed to the Airwallex Elements/drop-in front end to collect the card and confirm the payment;
     * `redirectUrl` is the hosted next-action URL when Airwallex returns one. When `paymentMethod` is
     * `authorize` the intent is created as a manual-capture authorization hold to {@see capture()} later.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $response = $this->client->post(
            AirwallexEndpoint::PaymentIntents,
            AirwallexPayload::createIntent($request),
            'create checkout session',
        );

        return new CheckoutSession(
            jwt: Value::nullableString($response['client_secret'] ?? null),
            redirectUrl: Value::nullableString(data_get($response, 'next_action.url')),
            reference: Value::nullableString($response['id'] ?? null),
            merchantReference: Value::nullableString($response['merchant_order_id'] ?? null) ?? $request->orderReference,
            raw: $response,
        );
    }

    /**
     * Charge a tokenized card server-side by creating a PaymentIntent then confirming it.
     *
     * The request's transient token is an Airwallex PaymentMethod id (produced client-side by
     * Airwallex.js, so no PAN reaches the server). A PaymentIntent is created for the amount —
     * capturing immediately, or as a manual-capture authorization hold when `capture` is false —
     * then confirmed against that payment method. When the card requires a 3-D Secure challenge the
     * intent resolves to a pending status with the challenge in `next_action`.
     */
    public function charge(ChargeRequest $request): PaymentResult
    {
        $intent = $this->client->post(
            AirwallexEndpoint::PaymentIntents,
            AirwallexPayload::chargeIntent($request),
            'create charge intent',
        );

        $intentId = Value::string($intent['id'] ?? null);

        $confirmed = $this->client->post(
            AirwallexEndpoint::PaymentIntentConfirm,
            AirwallexPayload::confirmMethod($request),
            'confirm charge',
            $intentId,
        );

        return $this->intentResult($confirmed, $intentId, $request->capture ? PaymentStatus::Captured : PaymentStatus::Authorized);
    }

    /**
     * Capture funds on an authorized (manual-capture) PaymentIntent.
     *
     * The intent is referenced by its id (the request's transaction id); a smaller amount performs a
     * partial capture.
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        $response = $this->client->post(
            AirwallexEndpoint::PaymentIntentCapture,
            AirwallexPayload::capture($request),
            'capture',
            $request->transactionId,
        );

        return $this->intentResult($response, $request->transactionId, PaymentStatus::Captured);
    }

    /**
     * Refund all or part of a captured PaymentIntent.
     *
     * The intent is referenced by its id (the request's transaction id); a smaller amount performs a
     * partial refund.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->post(
            AirwallexEndpoint::Refunds,
            AirwallexPayload::refund($request),
            'refund',
        );

        $status = $this->refundStatus(Value::nullableString($response['status'] ?? null));

        return new RefundResult(
            success: $status !== PaymentStatus::Failed,
            status: $status,
            refundId: Value::nullableString($response['id'] ?? null),
            code: Value::nullableString($response['status'] ?? null),
            message: Value::nullableString($response['failure_reason'] ?? $response['reason'] ?? null),
            raw: $response,
        );
    }

    /**
     * Void an uncaptured PaymentIntent by cancelling it.
     *
     * The intent is referenced by its id (the request's transaction id); Airwallex has no partial
     * cancel, so the whole intent is voided.
     */
    public function void(VoidRequest $request): PaymentResult
    {
        $response = $this->client->post(
            AirwallexEndpoint::PaymentIntentCancel,
            AirwallexPayload::cancel($this->cancelRequestId($request->idempotencyKey, $request->orderReference, $request->transactionId), null),
            'void',
            $request->transactionId,
        );

        return $this->cancelResult($response, $request->transactionId, PaymentStatus::Voided);
    }

    /**
     * Reverse (release) an authorization hold by cancelling the manual-capture PaymentIntent.
     *
     * Cancels the uncaptured authorization referenced by the request's transaction id, releasing the
     * held funds.
     */
    public function reverseAuthorization(ReversalRequest $request): PaymentResult
    {
        $response = $this->client->post(
            AirwallexEndpoint::PaymentIntentCancel,
            AirwallexPayload::cancel($this->cancelRequestId($request->idempotencyKey, $request->orderReference, $request->transactionId), $request->reason),
            'reverse authorization',
            $request->transactionId,
        );

        return $this->cancelResult($response, $request->transactionId, PaymentStatus::Reversed);
    }

    /**
     * Vault a card into a reusable PaymentConsent for later stored-credential charges.
     *
     * Creates a merchant-triggerable PaymentConsent against the customer ({@see TokenizeInstrumentRequest::$customerReference}
     * is the Airwallex customer id), then verifies it with the card — the PAN when supplied, or a
     * client-tokenized PaymentMethod id via the transient token. The returned instrument's
     * `paymentInstrumentId` is the payment_consent_id to pass to {@see chargeStoredCredential()}.
     * Card verification may require a 3-D Secure step, in which case the consent stays unverified
     * until the challenge in `next_action` is completed.
     */
    public function vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument
    {
        $consent = $this->client->post(
            AirwallexEndpoint::PaymentConsents,
            AirwallexPayload::createConsent($request),
            'create payment consent',
        );

        $consentId = Value::string($consent['id'] ?? null);

        $verified = $this->client->post(
            AirwallexEndpoint::PaymentConsentVerify,
            AirwallexPayload::verifyConsent($request, $this->gatewayCredentials->currency),
            'verify payment consent',
            $consentId,
        );

        $status = Value::nullableString($verified['status'] ?? $consent['status'] ?? null);

        return new VaultedInstrument(
            success: filled($consentId) && strtoupper((string) $status) !== 'FAILED',
            customerId: Value::nullableString($verified['customer_id'] ?? $request->customerReference),
            paymentInstrumentId: Value::nullableString($consentId),
            raw: $verified,
        );
    }

    /**
     * Charge a saved card (stored credential) fully server-side.
     *
     * Creates a PaymentIntent for the amount, then confirms it against the stored PaymentConsent
     * (the request's `paymentInstrumentId`), which moves the money under the consent's card-on-file
     * agreement. The result reports the confirmed intent's id and status.
     */
    public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult
    {
        $intent = $this->client->post(
            AirwallexEndpoint::PaymentIntents,
            AirwallexPayload::storedCredentialIntent($request),
            'create stored credential intent',
        );

        $intentId = Value::string($intent['id'] ?? null);

        $confirmed = $this->client->post(
            AirwallexEndpoint::PaymentIntentConfirm,
            AirwallexPayload::confirmConsent($request),
            'confirm stored credential',
            $intentId,
        );

        return $this->intentResult($confirmed, $intentId, PaymentStatus::Captured);
    }

    /**
     * Look up a PaymentIntent's current status via `GET /api/v1/pa/payment_intents/{id}`.
     *
     * @param  string  $transactionId  The Airwallex PaymentIntent id.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        $response = $this->client->get(AirwallexEndpoint::PaymentIntent, $transactionId, 'get transaction');

        return new TransactionSnapshot(
            transactionId: Value::string($response['id'] ?? $transactionId, $transactionId),
            status: AirwallexIntentStatus::toPaymentStatusOrFailed(Value::nullableString($response['status'] ?? null)),
            money: $this->money($response),
            orderReference: Value::nullableString($response['merchant_order_id'] ?? null) ?? $transactionId,
            raw: $response,
        );
    }

    /**
     * Find the most recent PaymentIntent for a merchant order reference.
     *
     * Lists PaymentIntents filtered by `merchant_order_id` and maps the first match to a snapshot,
     * returning null when nothing matches.
     *
     * @param  string  $query  The merchant order reference to search by.
     */
    public function searchTransaction(string $query): ?TransactionSnapshot
    {
        $response = $this->client->query(
            AirwallexEndpoint::PaymentIntentList,
            ['merchant_order_id' => $query],
            'search transaction',
        );

        $items = $response['items'] ?? null;

        if (! is_array($items) || ! isset($items[0]) || ! is_array($items[0])) {
            return null;
        }

        $intent = Value::array($items[0]);

        return new TransactionSnapshot(
            transactionId: Value::string($intent['id'] ?? null),
            status: AirwallexIntentStatus::toPaymentStatusOrFailed(Value::nullableString($intent['status'] ?? null)),
            money: $this->money($intent),
            orderReference: Value::nullableString($intent['merchant_order_id'] ?? null) ?? $query,
            raw: $intent,
        );
    }

    /**
     * Verify an Airwallex webhook by recomputing its HMAC-SHA256 signature.
     *
     * Airwallex signs the notification with the merchant's webhook secret over the concatenation of
     * the `x-timestamp` header and the raw request body, sending the hex digest in `x-signature`. The
     * event body is always decoded so a caller can inspect it even when verification fails; the
     * affected intent's id and status are read from the event's `data.object`.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $payload = Value::array(json_decode($rawBody, true));
        $secret = Value::string($this->gatewayCredentials->webhookSecret ?? null);
        $timestamp = $this->header($headers, 'x-timestamp');
        $signature = $this->header($headers, 'x-signature');

        $expected = hash_hmac('sha256', $timestamp.$rawBody, $secret);

        $eventType = Value::nullableString($payload['name'] ?? null);
        $object = Value::array(data_get($payload, 'data.object'));

        return new WebhookEvent(
            verified: filled($secret) && $timestamp !== null && $signature !== null && hash_equals($expected, $signature),
            eventType: $eventType,
            transactionId: Value::nullableString($object['id'] ?? null),
            status: $this->webhookStatus($eventType, $object),
            payload: $payload,
        );
    }

    /**
     * Map a PaymentIntent response to a PaymentResult, folding its status onto the SDK's enum.
     *
     * @param  array<string, mixed>  $response
     */
    private function intentResult(array $response, string $fallbackId, PaymentStatus $completedAs): PaymentResult
    {
        $rawStatus = Value::nullableString($response['status'] ?? null);

        $status = $rawStatus === AirwallexIntentStatus::Succeeded->value
            ? $completedAs
            : AirwallexIntentStatus::toPaymentStatusOrFailed($rawStatus);

        return new PaymentResult(
            success: $status->isSuccessful(),
            status: $status,
            transactionId: Value::nullableString($response['id'] ?? null) ?? $fallbackId,
            code: $rawStatus,
            message: Value::nullableString(data_get($response, 'latest_payment_attempt.status')),
            raw: $response,
        );
    }

    /**
     * Map a cancel response to a PaymentResult with the given terminal status (voided or reversed).
     *
     * A cancelled intent reports Airwallex status `CANCELLED`; the driver surfaces the caller's
     * intended status (void vs reversal) on success.
     *
     * @param  array<string, mixed>  $response
     */
    private function cancelResult(array $response, string $fallbackId, PaymentStatus $cancelledAs): PaymentResult
    {
        $rawStatus = Value::nullableString($response['status'] ?? null);
        $cancelled = strtoupper((string) $rawStatus) === AirwallexIntentStatus::Cancelled->value;

        return new PaymentResult(
            success: $cancelled,
            status: $cancelled ? $cancelledAs : AirwallexIntentStatus::toPaymentStatusOrFailed($rawStatus),
            transactionId: Value::nullableString($response['id'] ?? null) ?? $fallbackId,
            code: $rawStatus,
            raw: $response,
        );
    }

    /**
     * Resolve the cancel idempotency `request_id`, preferring the explicit key, then the order
     * reference, then the transaction id.
     */
    private function cancelRequestId(?string $idempotencyKey, ?string $orderReference, string $transactionId): string
    {
        return Value::nullableString($idempotencyKey)
            ?? Value::nullableString($orderReference)
            ?? $transactionId;
    }

    /**
     * Map an Airwallex refund `status` onto the SDK's normalized status.
     *
     * A settled refund is `SUCCEEDED`; `RECEIVED`/`PENDING` are still processing; anything else
     * (e.g. `FAILED`, `CANCELLED`) is a failure.
     */
    private function refundStatus(?string $status): PaymentStatus
    {
        return match (strtoupper((string) $status)) {
            'SUCCEEDED' => PaymentStatus::Refunded,
            'RECEIVED', 'PENDING' => PaymentStatus::Pending,
            default => PaymentStatus::Failed,
        };
    }

    /**
     * Best-effort PaymentStatus for a webhook event from its name and the event object's status.
     *
     * Refund events map on their own status; payment-intent events prefer the object's `status`
     * (via {@see AirwallexIntentStatus}) and fall back to the event name when the object carries none.
     *
     * @param  array<string, mixed>  $object
     */
    private function webhookStatus(?string $eventType, array $object): ?PaymentStatus
    {
        $name = strtolower((string) $eventType);

        if (blank($name)) {
            return null;
        }

        if (str_starts_with($name, 'refund.')) {
            return $this->refundStatus(Value::nullableString($object['status'] ?? null));
        }

        $objectStatus = Value::nullableString($object['status'] ?? null);

        if ($objectStatus !== null) {
            return AirwallexIntentStatus::toPaymentStatusOrFailed($objectStatus);
        }

        return match ($name) {
            'payment_intent.succeeded' => PaymentStatus::Captured,
            'payment_intent.requires_capture' => PaymentStatus::Authorized,
            'payment_intent.cancelled' => PaymentStatus::Voided,
            default => PaymentStatus::Pending,
        };
    }

    /**
     * Build the Money for a PaymentIntent from its major-unit `amount` and `currency`.
     *
     * @param  array<string, mixed>  $response
     */
    private function money(array $response): ?Money
    {
        $amount = Value::nullableString($response['amount'] ?? null);
        $currency = Value::nullableString($response['currency'] ?? null);

        if ($amount === null || $currency === null || preg_match('/^\d+(\.\d+)?$/', $amount) !== 1) {
            return null;
        }

        return Money::fromDecimalString($amount, $currency);
    }

    /**
     * Read a header by name (case-insensitively), taking the first value of a list.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return Value::nullableString(is_array($value) ? ($value[0] ?? null) : $value);
            }
        }

        return null;
    }
}
