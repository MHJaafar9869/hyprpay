<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Concerns;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsGatewayCode;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsOrderStatus;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsResult;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Maps decoded MPGS JSON responses into the SDK's normalized result DTOs.
 *
 * Shared by every MPGS operation so response reading — status resolution, transaction
 * id, gateway code, message, and amount extraction — lives in one place. Transaction
 * responses resolve their status from the `result` field plus an operation-specific
 * success status (refining failures via {@see MpgsGatewayCode}), while retrieved orders
 * map their aggregate `status` through {@see MpgsOrderStatus}.
 */
trait MapsMpgsResponses
{
    /**
     * Map an MPGS transaction response into a PaymentResult.
     *
     * @param  array<string, mixed>  $response  Decoded MPGS transaction response body.
     * @param  PaymentStatus  $onSuccess  Normalized status to apply when the request succeeded.
     */
    private function toPaymentResult(array $response, PaymentStatus $onSuccess): PaymentResult
    {
        $status = $this->resolveStatus($response, $onSuccess);

        return new PaymentResult(
            success: $status->isSuccessful(),
            status: $status,
            transactionId: $this->transactionId($response),
            code: $this->gatewayCode($response),
            message: $this->message($response),
            raw: $response,
        );
    }

    /**
     * Map a retrieved MPGS order into a TransactionSnapshot.
     *
     * @param  array<string, mixed>  $response  Decoded MPGS order response body.
     */
    private function toSnapshot(array $response): TransactionSnapshot
    {
        $orderId = Value::string($response['id'] ?? data_get($response, 'order.id'));

        return new TransactionSnapshot(
            transactionId: $orderId,
            status: MpgsOrderStatus::toPaymentStatusOrFailed($this->orderStatus($response)),
            money: $this->snapshotMoney($response),
            orderReference: $orderId !== '' ? $orderId : null,
            raw: $response,
        );
    }

    /**
     * Map an MPGS 3-D Secure (authentication) response into a PayerAuthResult.
     *
     * Surfaces the issuer challenge (ACS/redirect) URL as the step-up URL when present and
     * carries the whole authentication block for the caller to render the challenge.
     *
     * @param  array<string, mixed>  $response  Decoded MPGS authentication response body.
     */
    private function toPayerAuthResult(array $response): PayerAuthResult
    {
        $authentication = Value::array($response['authentication'] ?? null);
        $result = MpgsResult::fromResponse(Value::nullableString($response['result'] ?? null));

        return new PayerAuthResult(
            success: $result->isSuccessful(),
            status: Value::string(
                data_get($response, 'response.gatewayRecommendation')
                ?? $authentication['version']
                ?? $response['result']
                ?? null,
            ),
            stepUpUrl: Value::nullableString(
                data_get($authentication, '3ds2.acsUrl') ?? data_get($authentication, 'redirect.url'),
            ),
            authenticationTransactionId: $this->transactionId($response),
            consumerAuthenticationInformation: $authentication,
            raw: $response,
        );
    }

    /**
     * Resolve the normalized PaymentStatus for a transaction response.
     *
     * A success maps to the operation's success status, a pending result to Pending, and a
     * failure/unknown result to the status implied by the gateway code (Declined or Failed).
     *
     * @param  array<string, mixed>  $response  Decoded MPGS transaction response body.
     * @param  PaymentStatus  $onSuccess  Normalized status to apply when the request succeeded.
     */
    private function resolveStatus(array $response, PaymentStatus $onSuccess): PaymentStatus
    {
        return match (MpgsResult::fromResponse(Value::nullableString($response['result'] ?? null))) {
            MpgsResult::Success => $onSuccess,
            MpgsResult::Pending => PaymentStatus::Pending,
            MpgsResult::Failure, MpgsResult::Unknown => MpgsGatewayCode::toPaymentStatusOrFailed($this->gatewayCode($response)),
        };
    }

    /**
     * Extract the transaction id from a response, preferring the transaction block and
     * falling back to the top-level id.
     *
     * @param  array<string, mixed>  $response  Decoded MPGS response body.
     */
    private function transactionId(array $response): ?string
    {
        return Value::nullableString(data_get($response, 'transaction.id') ?? $response['id'] ?? null);
    }

    /**
     * Extract the gateway response code from a response, or null when absent.
     *
     * @param  array<string, mixed>  $response  Decoded MPGS response body.
     */
    private function gatewayCode(array $response): ?string
    {
        return Value::nullableString(data_get($response, 'response.gatewayCode'));
    }

    /**
     * Extract a human-readable message from a response, preferring the error explanation
     * and falling back to the gateway code or result, or null when none is present.
     *
     * @param  array<string, mixed>  $response  Decoded MPGS response body.
     */
    private function message(array $response): ?string
    {
        return Value::nullableString(
            data_get($response, 'error.explanation')
            ?? data_get($response, 'response.gatewayCode')
            ?? $response['result']
            ?? null,
        );
    }

    /**
     * Extract the order-level status string from a retrieved order or webhook, preferring
     * the top-level `status` and falling back to the nested order block.
     *
     * @param  array<string, mixed>  $response  Decoded MPGS order/webhook body.
     */
    private function orderStatus(array $response): ?string
    {
        return Value::nullableString($response['status'] ?? data_get($response, 'order.status'));
    }

    /**
     * Build the Money for a retrieved order from its amount and currency, or null when
     * either is missing or not a valid decimal amount.
     *
     * @param  array<string, mixed>  $response  Decoded MPGS order response body.
     */
    private function snapshotMoney(array $response): ?Money
    {
        $amount = Value::nullableString($response['amount'] ?? data_get($response, 'order.amount'));
        $currency = Value::nullableString($response['currency'] ?? data_get($response, 'order.currency'));

        if ($amount === null || $currency === null || preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            return null;
        }

        return Money::fromDecimalString($amount, $currency);
    }
}
