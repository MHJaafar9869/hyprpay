<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Concerns;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\DccQuote;
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
     * Map an MPGS `paymentOptionsInquiry` response into a DccQuote.
     *
     * MPGS returns the Dynamic Currency Conversion offer in a `currencyConversion` block; DCC is taken
     * as offered when it carries a converted `payerAmount`, a `payerCurrency`, and an exchange rate.
     * Unlike CyberSource, MPGS issues no rate id — the conversion is re-declared on the follow-on
     * payment — so the quote's id stays null. The original amount is echoed from the request.
     *
     * @param  array<string, mixed>  $response  Decoded MPGS payment-options-inquiry response body.
     * @param  Money  $original  The original amount and merchant currency the quote was requested for.
     */
    private function toDccQuote(array $response, Money $original): DccQuote
    {
        $conversion = Value::array(
            data_get($response, 'currencyConversion') ?? data_get($response, 'paymentOptionsInquiry.currencyConversion'),
        );

        $payerAmount = Value::nullableString($conversion['payerAmount'] ?? null);
        $payerCurrency = Value::nullableString($conversion['payerCurrency'] ?? null);
        $exchangeRate = Value::nullableString(
            $conversion['payerExchangeRate'] ?? $conversion['exchangeRate'] ?? $conversion['rate'] ?? null,
        );

        $offered = $payerAmount !== null
            && $payerCurrency !== null
            && preg_match('/^-?\d+(\.\d+)?$/', $payerAmount) === 1;

        return new DccQuote(
            offered: $offered,
            exchangeRate: $exchangeRate,
            originalAmount: $original,
            convertedAmount: $offered ? Money::fromDecimalString((string) $payerAmount, (string) $payerCurrency) : null,
            exchangeRateTimeStamp: Value::nullableString($conversion['timestamp'] ?? null),
            raw: $response,
        );
    }

    /**
     * Map a retrieved MPGS order's `transaction[]` history into per-transaction snapshots, newest first.
     *
     * MPGS returns an order's transactions oldest-first, so the list is reversed to honour the SDK's
     * newest-first contract. Each element's status is resolved from its own `result` and transaction
     * type (an authorization, capture, refund, or void), falling back to the aggregate order status.
     *
     * @param  array<string, mixed>  $order  Decoded MPGS retrieved-order response body.
     * @param  string  $orderId  The order id the history was retrieved for (used as the reference fallback).
     * @return list<TransactionSnapshot>
     */
    private function orderTransactionSnapshots(array $order, string $orderId): array
    {
        $transactions = $order['transaction'] ?? data_get($order, 'order.transaction');

        if (! is_array($transactions)) {
            return [];
        }

        $orderStatus = $this->orderStatus($order);
        $elements = array_values(array_filter($transactions, is_array(...)));

        return array_values(array_map(
            fn (mixed $element): TransactionSnapshot => $this->orderTransactionSnapshot(Value::array($element), $orderId, $orderStatus),
            array_reverse($elements),
        ));
    }

    /**
     * Map a single element of an order's `transaction[]` history into a TransactionSnapshot.
     *
     * @param  array<string, mixed>  $element  One MPGS transaction-history element.
     * @param  string  $orderId  The order id (reference/transaction-id fallback).
     * @param  string|null  $orderStatus  The aggregate order status, used when the element's type is unknown.
     */
    private function orderTransactionSnapshot(array $element, string $orderId, ?string $orderStatus): TransactionSnapshot
    {
        $fallback = $orderStatus !== null ? MpgsOrderStatus::toPaymentStatusOrFailed($orderStatus) : PaymentStatus::Pending;
        $type = Value::nullableString(data_get($element, 'transaction.type'));

        return new TransactionSnapshot(
            transactionId: Value::string(data_get($element, 'transaction.id') ?? $element['id'] ?? $orderId, $orderId),
            status: $this->resolveStatus($element, $this->transactionTypeStatus($type, $fallback)),
            money: $this->transactionMoney($element),
            orderReference: Value::nullableString(data_get($element, 'order.id')) ?? $orderId,
            raw: $element,
        );
    }

    /**
     * Resolve the success status implied by an MPGS transaction type, falling back to the order status
     * when the type is absent or unrecognised.
     *
     * @param  string|null  $type  The MPGS `transaction.type` (e.g. AUTHORIZATION, PAYMENT, REFUND, VOID_*).
     * @param  PaymentStatus  $fallback  Status used when the type is unknown.
     */
    private function transactionTypeStatus(?string $type, PaymentStatus $fallback): PaymentStatus
    {
        return match (strtoupper((string) $type)) {
            'AUTHORIZATION', 'AUTHORIZE' => PaymentStatus::Authorized,
            'PAY', 'PAYMENT', 'CAPTURE' => PaymentStatus::Captured,
            'REFUND' => PaymentStatus::Refunded,
            'VOID', 'VOID_AUTHORIZATION', 'VOID_CAPTURE', 'VOID_PAYMENT', 'VOID_REFUND' => PaymentStatus::Voided,
            'VERIFICATION', 'VERIFY' => PaymentStatus::Pending,
            default => $fallback,
        };
    }

    /**
     * Build the Money for a transaction-history element from its transaction, order, or top-level
     * amount, or null when no valid decimal amount is present.
     *
     * @param  array<string, mixed>  $element  One MPGS transaction-history element.
     */
    private function transactionMoney(array $element): ?Money
    {
        $amount = Value::nullableString(
            data_get($element, 'transaction.amount') ?? data_get($element, 'order.amount') ?? $element['amount'] ?? null,
        );
        $currency = Value::nullableString(
            data_get($element, 'transaction.currency') ?? data_get($element, 'order.currency') ?? $element['currency'] ?? null,
        );

        if ($amount === null || $currency === null || preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            return null;
        }

        return Money::fromDecimalString($amount, $currency);
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
