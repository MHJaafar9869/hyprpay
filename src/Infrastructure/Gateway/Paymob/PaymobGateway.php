<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
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
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Enums\PaymobPaymentMethod;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Enums\PaymobTransactionStatus;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Payloads\PaymobOrderPayload;
use Hyprpay\Payments\Infrastructure\Gateway\Paymob\Payloads\PaymobPaymentKeyPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Paymob (Accept) payment gateway adapter.
 *
 * Implements the operations Paymob's Accept API provides: starting a payment via
 * {@see createCheckoutSession()} (the auth-token → order → payment-key → iframe flow),
 * capturing an authorization, refunding, voiding, looking up a transaction by Paymob
 * order id ({@see getTransaction()}) or by the merchant order id ({@see searchTransaction()}),
 * and verifying the HMAC-SHA512 transaction webhook. Reversal, payer-auth, and vaulting
 * are not part of this flow and inherit {@see AbstractPaymentGateway}'s unsupported behaviour.
 *
 * Requests are built deterministically from the caller's inputs — the Paymob
 * `merchant_order_id` is the caller's order reference with no random suffix — so a
 * retried checkout reuses the same order and Paymob deduplicates it.
 */
final class PaymobGateway extends AbstractPaymentGateway
{
    private const IFRAME_URL = 'https://accept.paymob.com/api/acceptance/iframes/%s?payment_token=%s';

    private const DEFAULT_EXPIRATION_SECONDS = 3600;

    private readonly PaymobClient $client;

    /**
     * Construct the gateway, wiring a PaymobClient from the credentials and HTTP port.
     */
    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);

        $this->client = new PaymobClient($http, $credentials);
    }

    /**
     * Identify this driver as the Paymob gateway.
     */
    public function name(): GatewayName
    {
        return GatewayName::Paymob;
    }

    /**
     * Start a Paymob payment: authenticate, register the order, request a payment key,
     * and return the iframe redirect URL plus the Paymob order reference.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $method = PaymobPaymentMethod::fromRequest($request->paymentMethod);
        $integrationId = $this->integrationId($request, $method);

        $authToken = $this->client->authenticate();

        $order = $this->client->post(
            '/ecommerce/orders',
            PaymobOrderPayload::build($request),
            $authToken,
            'register order',
        );
        $orderId = $order['id'] ?? null;

        $paymentKey = $this->client->post(
            '/acceptance/payment_keys',
            PaymobPaymentKeyPayload::build(
                $request,
                Value::string($orderId),
                $authToken,
                $integrationId,
                Value::int($request->optionsArray()['expiration'] ?? self::DEFAULT_EXPIRATION_SECONDS, self::DEFAULT_EXPIRATION_SECONDS),
            ),
            $authToken,
            'create payment key',
        );
        $token = Value::string($paymentKey['token'] ?? null);

        $iframeId = $this->iframeId($request, $method);

        return new CheckoutSession(
            redirectUrl: filled($iframeId) ? sprintf(self::IFRAME_URL, $iframeId, $token) : null,
            reference: filled($orderId) ? Value::string($orderId) : null,
            merchantReference: $request->orderReference,
            raw: ['order' => $order, 'payment_token' => $token],
        );
    }

    /**
     * Capture funds on a previously authorized Paymob transaction.
     *
     * Applies to transactions created against an auth-only (authorization) integration;
     * the captured amount is the request's amount in minor units, so a smaller amount
     * performs a partial capture. Mirrors {@see void()}: the outcome is derived from
     * Paymob's `error_occured` flag rather than a status string.
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        $response = $this->client->post(
            '/acceptance/capture',
            ['transaction_id' => $request->transactionId, 'amount_cents' => $request->money->minorAmount],
            $this->client->authenticate(),
            'capture',
        );

        $succeeded = ! (bool) ($response['error_occured'] ?? false);

        return new PaymentResult(
            success: $succeeded,
            status: $succeeded ? PaymentStatus::Captured : PaymentStatus::Failed,
            transactionId: isset($response['id']) ? Value::string($response['id']) : $request->transactionId,
            message: Value::nullableString(data_get($response, 'data.message')),
            raw: $response,
        );
    }

    /**
     * Refund all or part of a Paymob transaction.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->post(
            '/acceptance/void_refund/refund',
            ['transaction_id' => $request->transactionId, 'amount_cents' => $request->money->minorAmount],
            $this->client->authenticate(),
            'refund',
        );

        $succeeded = ! (bool) ($response['error_occured'] ?? false);

        return new RefundResult(
            success: $succeeded,
            status: $succeeded ? PaymentStatus::Refunded : PaymentStatus::Failed,
            refundId: isset($response['id']) ? Value::string($response['id']) : $request->transactionId,
            message: Value::nullableString(data_get($response, 'data.message')),
            raw: $response,
        );
    }

    /**
     * Void an uncaptured Paymob transaction (same-day cancellation).
     */
    public function void(VoidRequest $request): PaymentResult
    {
        $response = $this->client->post(
            '/acceptance/void_refund/void',
            ['transaction_id' => $request->transactionId],
            $this->client->authenticate(),
            'void',
        );

        $succeeded = ! (bool) ($response['error_occured'] ?? false);

        return new PaymentResult(
            success: $succeeded,
            status: $succeeded ? PaymentStatus::Voided : PaymentStatus::Failed,
            transactionId: isset($response['id']) ? Value::string($response['id']) : $request->transactionId,
            message: Value::nullableString(data_get($response, 'data.message')),
            raw: $response,
        );
    }

    /**
     * Look up a Paymob order's transaction status via order inquiry.
     *
     * @param  string  $transactionId  The Paymob order id returned when the checkout was created.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        $response = $this->client->post(
            '/ecommerce/orders/transaction_inquiry',
            ['order_id' => $transactionId],
            $this->client->authenticate(),
            'get transaction',
        );

        return new TransactionSnapshot(
            transactionId: Value::string($response['id'] ?? $transactionId),
            status: PaymobTransactionStatus::fromTransaction($response),
            orderReference: Value::string(data_get($response, 'order.merchant_order_id') ?? $transactionId),
            raw: $response,
        );
    }

    /**
     * Search for a Paymob transaction by the merchant's own order reference.
     *
     * Runs the same order-inquiry endpoint as {@see getTransaction()} but keyed by the
     * `merchant_order_id` supplied at checkout instead of the Paymob order id, returning
     * null when Paymob reports no matching order.
     *
     * @param  string  $query  The merchant order id (the order reference used at checkout).
     */
    public function searchTransaction(string $query): ?TransactionSnapshot
    {
        $response = $this->client->post(
            '/ecommerce/orders/transaction_inquiry',
            ['merchant_order_id' => $query],
            $this->client->authenticate(),
            'search transaction',
        );

        if (blank($response['id'] ?? null)) {
            return null;
        }

        return new TransactionSnapshot(
            transactionId: Value::string($response['id']),
            status: PaymobTransactionStatus::fromTransaction($response),
            orderReference: Value::string(data_get($response, 'order.merchant_order_id') ?? $query),
            raw: $response,
        );
    }

    /**
     * Verify a Paymob transaction webhook and parse it into an event.
     *
     * Paymob delivers the transaction object under `obj` and the HMAC as an `hmac`
     * query parameter; pass that value in the headers map under `hmac` (it is also
     * read from an `hmac` body field as a fallback). The signature is HMAC-SHA512
     * over Paymob's fixed field order, keyed by the webhook (HMAC) secret.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $decoded = json_decode($rawBody, true);
        $payload = Value::array($decoded);
        $transaction = is_array($payload['obj'] ?? null) ? Value::array($payload['obj']) : $payload;

        $expected = PaymobHmac::forTransaction($transaction, Value::string($this->gatewayCredentials->webhookSecret));
        $provided = $this->providedHmac($payload, $headers);

        return new WebhookEvent(
            verified: filled($provided) && hash_equals($expected, $provided),
            eventType: Value::nullableString($payload['type'] ?? 'TRANSACTION'),
            transactionId: Value::nullableString($transaction['id'] ?? null),
            status: PaymobTransactionStatus::fromTransaction($transaction),
            payload: $payload,
        );
    }

    /**
     * Resolve the Paymob integration id for the method, from the request or credentials.
     */
    private function integrationId(CheckoutSessionRequest $request, PaymobPaymentMethod $method): string
    {
        $integrationId = $request->optionsArray()['integration_id']
            ?? $this->gatewayCredentials->extra("integrations.{$method->value}");

        if (blank($integrationId)) {
            throw new GatewayRequestException(
                status: 0,
                responseBody: '',
                message: "Paymob integration id is not configured for the '{$method->value}' method.",
            );
        }

        return Value::string($integrationId);
    }

    /**
     * Resolve the Paymob iframe id for the method, from the request or credentials.
     */
    private function iframeId(CheckoutSessionRequest $request, PaymobPaymentMethod $method): ?string
    {
        $iframeId = $request->optionsArray()['iframe_id']
            ?? $this->gatewayCredentials->extra("iframes.{$method->value}");

        return Value::nullableString($iframeId);
    }

    /**
     * Extract the HMAC supplied with the webhook (headers `hmac`, else body `hmac`).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function providedHmac(array $payload, array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'hmac') {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return Value::nullableString($payload['hmac'] ?? null);
    }
}
