<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\AuthorizeNet;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\AuthorizeNet\Enums\AuthorizeNetTransactionStatus;
use Hyprpay\Payments\Infrastructure\Gateway\AuthorizeNet\Payloads\AuthorizeNetPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Authorize.Net (Authorize.net) payment gateway adapter.
 *
 * Implements the card lifecycle over Authorize.Net's JSON transaction API: charging an
 * Accept.js opaque-data token via {@see charge()} (immediate capture or auth-only),
 * capturing a prior authorization, refunding, voiding an unsettled transaction, looking up
 * a transaction for reconciliation ({@see getTransaction()}), and verifying the HMAC-SHA512
 * webhook signature. Checkout sessions, DCC, payer-auth, vaulting, stored-credential charges,
 * transaction search, and authorization reversal are not part of this flow and inherit
 * {@see AbstractPaymentGateway}'s unsupported behaviour.
 */
final class AuthorizeNetGateway extends AbstractPaymentGateway
{
    private readonly AuthorizeNetClient $client;

    /**
     * Construct the gateway, wiring an AuthorizeNetClient from the credentials and HTTP port.
     */
    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);

        $this->client = new AuthorizeNetClient($http, $credentials);
    }

    /**
     * Identify this driver as the Authorize.Net gateway.
     */
    public function name(): GatewayName
    {
        return GatewayName::AuthorizeNet;
    }

    /**
     * Charge (or authorize) an Accept.js opaque-data token.
     *
     * The request's transient token is the Accept.js payment nonce. When capture is requested
     * the money is captured immediately (authCapture); otherwise the funds are only authorized.
     */
    public function charge(ChargeRequest $request): PaymentResult
    {
        $response = $this->client->createTransaction(
            AuthorizeNetPayload::charge($request),
            $request->orderReference,
            'charge',
        );

        return $this->toPaymentResult($response, $request->capture ? PaymentStatus::Captured : PaymentStatus::Authorized);
    }

    /**
     * Capture a previously authorized transaction (prior-authorization capture).
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        $response = $this->client->createTransaction(
            AuthorizeNetPayload::capture($request),
            $request->orderReference,
            'capture',
        );

        return $this->toPaymentResult($response, PaymentStatus::Captured);
    }

    /**
     * Refund all or part of a settled transaction by referencing its id.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->createTransaction(
            AuthorizeNetPayload::refund($request),
            $request->orderReference,
            'refund',
        );

        $approved = $this->approved($response);

        return new RefundResult(
            success: $approved,
            status: $approved ? PaymentStatus::Refunded : $this->failureStatus($response),
            refundId: $this->transactionId($response),
            code: $this->responseCode($response),
            message: $this->message($response, $approved),
            raw: $response,
        );
    }

    /**
     * Void an unsettled authorization or capture by referencing its id.
     */
    public function void(VoidRequest $request): PaymentResult
    {
        $response = $this->client->createTransaction(
            AuthorizeNetPayload::void($request),
            $request->orderReference,
            'void',
        );

        return $this->toPaymentResult($response, PaymentStatus::Voided);
    }

    /**
     * Look up a transaction's authoritative status for reconciliation.
     *
     * @param  string  $transactionId  The Authorize.Net transaction id (transId).
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        $response = $this->client->getTransactionDetails($transactionId, 'get transaction');
        $transaction = Value::array($response['transaction'] ?? []);

        return new TransactionSnapshot(
            transactionId: Value::string($transaction['transId'] ?? $transactionId, $transactionId),
            status: AuthorizeNetTransactionStatus::fromTransaction($transaction),
            money: $this->money($transaction),
            orderReference: Value::nullableString(data_get($transaction, 'order.invoiceNumber')),
            raw: $response,
        );
    }

    /**
     * Verify an Authorize.Net webhook by recomputing its HMAC-SHA512 signature.
     *
     * Authorize.Net signs the raw notification body with the merchant Signature Key and sends
     * the hash as `X-ANET-Signature: sha512=<HEX>`. The event body is always decoded so a caller
     * can inspect it even when verification fails.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $payload = Value::array(json_decode($rawBody, true));
        $secret = Value::string($this->gatewayCredentials->webhookSecret ?? null);
        $signature = $this->signatureFrom($headers);
        $expected = strtoupper(hash_hmac('sha512', $rawBody, $secret));

        $eventType = Value::string($payload['eventType'] ?? null);
        $notification = Value::array($payload['payload'] ?? []);

        return new WebhookEvent(
            verified: $secret !== '' && $signature !== null && hash_equals($expected, strtoupper($signature)),
            eventType: Value::nullableString($payload['eventType'] ?? null),
            transactionId: Value::nullableString($notification['id'] ?? null),
            status: $this->webhookStatus($eventType, $notification),
            payload: $payload,
        );
    }

    /**
     * Map a createTransactionRequest response to a PaymentResult with the given success status.
     *
     * @param  array<string, mixed>  $response
     */
    private function toPaymentResult(array $response, PaymentStatus $successStatus): PaymentResult
    {
        $approved = $this->approved($response);

        return new PaymentResult(
            success: $approved,
            status: $approved ? $successStatus : $this->failureStatus($response),
            transactionId: $this->transactionId($response),
            code: $this->responseCode($response),
            message: $this->message($response, $approved),
            raw: $response,
        );
    }

    /**
     * A transaction is approved when the request was well-formed (resultCode Ok) and the
     * transaction itself was approved (responseCode 1).
     *
     * @param  array<string, mixed>  $response
     */
    private function approved(array $response): bool
    {
        return strtolower(Value::string(data_get($response, 'messages.resultCode'))) === 'ok'
            && $this->responseCode($response) === '1';
    }

    /**
     * Resolve the failure PaymentStatus from the transaction response code.
     *
     * @param  array<string, mixed>  $response
     */
    private function failureStatus(array $response): PaymentStatus
    {
        return match ($this->responseCode($response)) {
            '2' => PaymentStatus::Declined,
            '4' => PaymentStatus::Pending,
            default => PaymentStatus::Failed,
        };
    }

    /**
     * The transaction-level response code (1 approved, 2 declined, 3 error, 4 held), or null.
     *
     * @param  array<string, mixed>  $response
     */
    private function responseCode(array $response): ?string
    {
        return Value::nullableString(data_get($response, 'transactionResponse.responseCode'));
    }

    /**
     * The gateway transaction id, treating Authorize.Net's "0" placeholder as absent.
     *
     * @param  array<string, mixed>  $response
     */
    private function transactionId(array $response): ?string
    {
        $id = Value::nullableString(data_get($response, 'transactionResponse.transId'));

        return $id === '0' ? null : $id;
    }

    /**
     * The human-readable outcome message: the approval description, else the decline/error text.
     *
     * @param  array<string, mixed>  $response
     */
    private function message(array $response, bool $approved): ?string
    {
        if ($approved) {
            return Value::nullableString(data_get($response, 'transactionResponse.messages.0.description'));
        }

        return Value::nullableString(data_get($response, 'transactionResponse.errors.0.errorText'))
            ?? Value::nullableString(data_get($response, 'messages.message.0.text'));
    }

    /**
     * Extract the signature hash from the `X-ANET-Signature` header, dropping the `sha512=` prefix.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    private function signatureFrom(array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== 'x-anet-signature') {
                continue;
            }

            $raw = Value::string(is_array($value) ? ($value[0] ?? null) : $value);
            $separator = strpos($raw, '=');

            return $separator === false ? $raw : substr($raw, $separator + 1);
        }

        return null;
    }

    /**
     * Best-effort PaymentStatus for a webhook event from its type and response code.
     *
     * @param  array<string, mixed>  $notification
     */
    private function webhookStatus(string $eventType, array $notification): ?PaymentStatus
    {
        $type = strtolower($eventType);

        if (str_contains($type, 'refund')) {
            return PaymentStatus::Refunded;
        }

        if (str_contains($type, 'void')) {
            return PaymentStatus::Voided;
        }

        $code = $notification['responseCode'] ?? null;

        if ($code === null) {
            return null;
        }

        if (Value::int($code) !== 1) {
            return PaymentStatus::Declined;
        }

        return str_contains($type, 'authorization') && ! str_contains($type, 'capture')
            ? PaymentStatus::Authorized
            : PaymentStatus::Captured;
    }

    /**
     * Build the Money for a getTransactionDetails transaction from its settled/authorized amount.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function money(array $transaction): ?Money
    {
        $amount = Value::nullableString($transaction['settleAmount'] ?? $transaction['authAmount'] ?? null);

        if ($amount === null) {
            return null;
        }

        return Money::fromDecimalString($amount, $this->gatewayCredentials->currency);
    }
}
