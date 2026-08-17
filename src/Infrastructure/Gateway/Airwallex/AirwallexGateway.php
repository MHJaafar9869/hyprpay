<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Airwallex;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
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
 * Drives Airwallex's PaymentIntent flow: {@see createCheckoutSession()} creates a PaymentIntent and
 * returns its id together with the `client_secret` that initialises Airwallex's client-side
 * (Elements / drop-in) checkout — which collects the card and confirms the intent, so the interactive
 * card `charge` completes in the browser rather than server-side. The server-side follow-ons act on
 * the resulting intent: {@see capture()} settles a manual-capture (authorization-hold) intent,
 * {@see refund()} returns funds on a captured one, and {@see getTransaction()} reads an intent back
 * for reconciliation. {@see chargeStoredCredential()} charges a saved card fully server-side by
 * creating an intent and confirming it against a stored PaymentConsent. {@see verifyWebhook()}
 * validates a notification by recomputing its HMAC-SHA256 signature over the timestamp and raw body.
 * Interactive `charge`, `void`, DCC, payer-auth, and raw-card vaulting are not part of this driver and
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
            verified: $secret !== '' && $timestamp !== null && $signature !== null && hash_equals($expected, $signature),
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

        if ($name === '') {
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
