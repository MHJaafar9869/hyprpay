<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Tamara;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Tamara\Enums\TamaraEndpoint;
use Hyprpay\Payments\Infrastructure\Gateway\Tamara\Enums\TamaraOrderStatus;
use Hyprpay\Payments\Infrastructure\Gateway\Tamara\Payloads\TamaraCapturePayload;
use Hyprpay\Payments\Infrastructure\Gateway\Tamara\Payloads\TamaraCheckoutPayload;
use Hyprpay\Payments\Infrastructure\Gateway\Tamara\Payloads\TamaraMoney;
use Hyprpay\Payments\Infrastructure\Gateway\Tamara\Payloads\TamaraRefundPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Tamara "buy now, pay later" gateway driver.
 *
 * Implements the redirect-based Tamara online-checkout lifecycle over its REST API: create a
 * hosted checkout session, authorise the approved order, capture on fulfilment, cancel before
 * capture, and refund after it — plus order lookups and inbound webhook verification. Amounts
 * are carried exactly and every request is authenticated with the merchant's Bearer API token
 * via {@see TamaraClient}. Operations Tamara does not offer stay unsupported (inherited from
 * {@see AbstractPaymentGateway}).
 */
final class TamaraGateway extends AbstractPaymentGateway
{
    private readonly TamaraClient $client;

    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);
        $this->client = new TamaraClient($http, $credentials);
    }

    public function name(): GatewayName
    {
        return GatewayName::Tamara;
    }

    /**
     * Create a hosted checkout session and return the URL to redirect the customer to.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $response = $this->client->postJson(
            TamaraEndpoint::Checkout->path(),
            TamaraCheckoutPayload::build($request, $this->gatewayCredentials),
            'create checkout session',
        );

        return new CheckoutSession(
            redirectUrl: Value::nullableString($response['checkout_url'] ?? null),
            reference: Value::nullableString($response['order_id'] ?? null),
            merchantReference: Value::nullableString($response['order_reference_id'] ?? $request->orderReference),
            raw: $response,
        );
    }

    /**
     * Authorise a customer-approved order so it can subsequently be captured.
     *
     * Tamara requires this explicit step after the `order_approved` webhook and before capture.
     * It is specific to Tamara's flow and therefore not part of the shared PaymentGatewayInterface.
     *
     * @param  string  $orderId  The Tamara order id to authorise.
     */
    public function authorise(string $orderId): PaymentResult
    {
        $response = $this->client->postJson(TamaraEndpoint::Authorise->path($orderId), [], 'authorise order');

        return $this->toPaymentResult(
            $response,
            PaymentStatus::Authorized,
            Value::nullableString($response['order_id'] ?? $orderId),
        );
    }

    /**
     * Capture (settle) an authorised order, in full or in part.
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        $response = $this->client->postJson(TamaraEndpoint::Capture->path(), TamaraCapturePayload::build($request), 'capture');

        return $this->toPaymentResult(
            $response,
            PaymentStatus::Captured,
            Value::nullableString($response['capture_id'] ?? $response['order_id'] ?? null),
        );
    }

    /**
     * Refund a captured order, in full or in part.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->postJson(
            TamaraEndpoint::Refund->path($request->transactionId),
            TamaraRefundPayload::build($request),
            'refund',
        );

        $status = $this->resultStatus($response, PaymentStatus::Refunded);

        return new RefundResult(
            success: $status === PaymentStatus::Refunded,
            status: $status,
            refundId: Value::nullableString($response['refund_id'] ?? null),
            raw: $response,
        );
    }

    /**
     * Cancel an order before it is captured.
     *
     * Tamara's cancel requires the order's total amount, so the current order is fetched first
     * and its total is echoed back to cancel it in full.
     */
    public function void(VoidRequest $request): PaymentResult
    {
        $order = $this->client->getJson(TamaraEndpoint::Order->path($request->transactionId), 'fetch order for cancel');

        return $this->cancelOrder($request->transactionId, Value::array($order['total_amount'] ?? null), PaymentStatus::Voided);
    }

    /**
     * Reverse (release) an authorised-but-uncaptured order.
     *
     * Tamara exposes a single cancel operation for undoing an authorisation, so this reverses the
     * hold via the same endpoint, using the amount carried on the request without an extra lookup.
     */
    public function reverseAuthorization(ReversalRequest $request): PaymentResult
    {
        return $this->cancelOrder($request->transactionId, TamaraMoney::of($request->money), PaymentStatus::Reversed);
    }

    /**
     * Fetch a transaction's current state by its Tamara order id.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        return $this->toSnapshot($this->client->getJson(TamaraEndpoint::Order->path($transactionId), 'get transaction'));
    }

    /**
     * Look up a transaction by the merchant's order reference, or null when there is no match.
     */
    public function searchTransaction(string $query): ?TransactionSnapshot
    {
        return $this->findByReference($query);
    }

    /**
     * Return the transaction for a merchant reference only when it reached a successful state.
     */
    public function findSuccessfulTransactionByReference(string $reference): ?TransactionSnapshot
    {
        $snapshot = $this->findByReference($reference);

        return $snapshot?->status->isSuccessful() === true ? $snapshot : null;
    }

    /**
     * Verify an inbound Tamara webhook and parse its event.
     *
     * Tamara authenticates each webhook with the shared Authorization header registered for the
     * merchant's webhook URL, so verification compares that header against the configured
     * webhook secret in constant time.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $payload = Value::array(json_decode($rawBody, true));
        $eventType = Value::nullableString($payload['event_type'] ?? null);

        return new WebhookEvent(
            verified: $this->webhookAuthorized($headers),
            eventType: $eventType,
            transactionId: Value::nullableString($payload['order_id'] ?? null),
            status: $this->webhookStatus($eventType, $payload),
            payload: $payload,
        );
    }

    /**
     * Look up a transaction by merchant reference, returning null when Tamara reports no match.
     */
    private function findByReference(string $reference): ?TransactionSnapshot
    {
        try {
            $response = $this->client->getJson(TamaraEndpoint::OrderByReference->path($reference), 'find transaction by reference');
        } catch (GatewayRequestException) {
            return null;
        }

        return blank($response['order_id'] ?? null) ? null : $this->toSnapshot($response);
    }

    /**
     * Cancel an order for the given total amount and map the result, falling back to $fallback.
     *
     * @param  array<string, mixed>  $totalAmount
     */
    private function cancelOrder(string $orderId, array $totalAmount, PaymentStatus $fallback): PaymentResult
    {
        $response = $this->client->postJson(
            TamaraEndpoint::Cancel->path($orderId),
            ['total_amount' => $totalAmount],
            'cancel',
        );

        return $this->toPaymentResult(
            $response,
            $fallback,
            Value::nullableString($response['cancel_id'] ?? $response['order_id'] ?? null),
        );
    }

    /**
     * Build a payment result from an operation response, mapping its status (or the fallback).
     *
     * @param  array<string, mixed>  $response
     */
    private function toPaymentResult(array $response, PaymentStatus $fallback, ?string $transactionId): PaymentResult
    {
        $status = $this->resultStatus($response, $fallback);

        return new PaymentResult(
            success: $status->isSuccessful(),
            status: $status,
            transactionId: $transactionId,
            raw: $response,
        );
    }

    /**
     * Map an operation response's status, falling back to the operation's expected status when absent.
     *
     * @param  array<string, mixed>  $response
     */
    private function resultStatus(array $response, PaymentStatus $fallback): PaymentStatus
    {
        $raw = Value::nullableString($response['status'] ?? null);

        return $raw === null ? $fallback : TamaraOrderStatus::toPaymentStatusOrFailed($raw);
    }

    /**
     * Build a transaction snapshot from a Tamara order response.
     *
     * @param  array<string, mixed>  $response
     */
    private function toSnapshot(array $response): TransactionSnapshot
    {
        return new TransactionSnapshot(
            transactionId: Value::string($response['order_id'] ?? null),
            status: TamaraOrderStatus::toPaymentStatusOrFailed(Value::nullableString($response['status'] ?? null)),
            money: $this->money($response['total_amount'] ?? null),
            orderReference: Value::nullableString($response['order_reference_id'] ?? null),
            raw: $response,
        );
    }

    /**
     * Reconstruct a Money value from Tamara's {amount, currency} object, or null when absent.
     *
     * @param  mixed  $amount
     */
    private function money($amount): ?Money
    {
        $data = Value::array($amount);
        $value = Value::nullableString($data['amount'] ?? null);
        $currency = Value::nullableString($data['currency'] ?? null);

        return $value === null || $currency === null ? null : Money::fromDecimalString($value, $currency);
    }

    /**
     * Map a webhook event type onto a normalized status, falling back to the order status field.
     *
     * @param  array<string, mixed>  $payload
     */
    private function webhookStatus(?string $eventType, array $payload): ?PaymentStatus
    {
        return match ($eventType) {
            'order_approved' => PaymentStatus::Pending,
            'order_authorised' => PaymentStatus::Authorized,
            'order_captured' => PaymentStatus::Captured,
            'order_refunded' => PaymentStatus::Refunded,
            'order_canceled' => PaymentStatus::Voided,
            'order_declined' => PaymentStatus::Declined,
            'order_expired' => PaymentStatus::Failed,
            default => $this->orderStatusOrNull($payload),
        };
    }

    /**
     * Map the payload's order_status field when present, or null.
     *
     * @param  array<string, mixed>  $payload
     */
    private function orderStatusOrNull(array $payload): ?PaymentStatus
    {
        $status = Value::nullableString($payload['order_status'] ?? null);

        return $status === null ? null : TamaraOrderStatus::toPaymentStatusOrFailed($status);
    }

    /**
     * Verify the inbound webhook's Authorization header against the configured secret in constant time.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function webhookAuthorized(array $headers): bool
    {
        $secret = $this->gatewayCredentials->webhookSecret;

        if (blank($secret)) {
            return false;
        }

        $provided = $this->header($headers, 'authorization');

        return $provided !== null && hash_equals($secret, $provided);
    }

    /**
     * Read a request header by name, case-insensitively.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return Value::nullableString(is_array($value) ? ($value[0] ?? null) : $value);
            }
        }

        return null;
    }
}
