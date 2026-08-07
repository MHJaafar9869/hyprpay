<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
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
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\Enums\PaytabsEndpoint;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\Enums\PaytabsResponseStatus;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\Payloads\PaytabsPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * PayTabs PT2 payment gateway adapter.
 *
 * Covers PayTabs' integration types behind one driver. {@see createCheckoutSession()}
 * starts a payer-not-present flow selected by the request's payment method — the Hosted
 * Payment Page (`sale`, or an `auth` hold), an Invoice (`invoice`, returns an
 * emailable link), the iframe Managed Form (`managed`), or a reusable PayLink
 * (`paylink`). {@see charge()} runs an Own Form charge of a browser-generated
 * `payment_token`, and {@see chargeStoredCredential()} charges a saved card token
 * (create one by setting PaytabsCheckoutOptions::$tokenise on a checkout; {@see deleteToken()}
 * revokes it). Follow-ons are {@see capture()}, {@see refund()}, {@see void()},
 * {@see getTransaction()} (via `/payment/query`), and IPN/return callback verification.
 * Payer-auth, vaulting a raw PAN, and reversal are not part of this driver and inherit
 * {@see AbstractPaymentGateway}'s unsupported behaviour.
 *
 * Requests are built deterministically from the caller's inputs — the cart id is the
 * caller's order reference with no random or time suffix — so a retried request is
 * byte-for-byte identical and PayTabs treats it idempotently.
 */
final class PaytabsGateway extends AbstractPaymentGateway
{
    private readonly PaytabsClient $client;

    /**
     * Construct the gateway, wiring a PaytabsClient from the credentials and HTTP port.
     */
    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);

        $this->client = new PaytabsClient($http, $credentials);
    }

    /**
     * Identify this driver as the PayTabs gateway.
     */
    public function name(): GatewayName
    {
        return GatewayName::Paytabs;
    }

    /**
     * Start a PayTabs payment, choosing the integration type from the payment method.
     *
     * `invoice` creates an itemised Invoice and returns its `invoice_link`; `managed`
     * returns an iframe-embeddable payment page; `paylink` creates a reusable PayLink
     * and returns its `link_url`; anything else (default, or `auth` for a hold) is the
     * Hosted Payment Page and returns a `redirect_url` plus the `tran_ref` used for
     * follow-on capture/refund/void. Set PaytabsCheckoutOptions::$tokenise to have PayTabs
     * vault the card and return a reusable `token` in the callback/status.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $credentials = $this->gatewayCredentials;

        return match (strtolower((string) $request->paymentMethod)) {
            'invoice' => $this->checkout(PaytabsEndpoint::Request, PaytabsPayload::invoice($request, $credentials), $request),
            'managed' => $this->checkout(PaytabsEndpoint::Request, PaytabsPayload::managed($request, $credentials), $request),
            'paylink' => $this->checkout(PaytabsEndpoint::LinkCreate, PaytabsPayload::payLink($request, $credentials), $request),
            default => $this->checkout(PaytabsEndpoint::Request, PaytabsPayload::hosted($request, $credentials), $request),
        };
    }

    /**
     * Charge a browser-generated `payment_token` server-to-server (Own Form).
     *
     * Captures immediately unless the request asks to authorize only. A 3-D Secure card
     * comes back Pending with a `redirect_url` in the result's raw payload — send the
     * payer there to authenticate; a non-3DS card returns the final outcome inline.
     */
    public function charge(ChargeRequest $request): PaymentResult
    {
        $response = $this->client->post(
            PaytabsEndpoint::Request,
            PaytabsPayload::ownForm($request, $this->gatewayCredentials),
            'charge',
        );

        return $this->toPaymentResult($response, $request->orderReference);
    }

    /**
     * Charge a previously saved PayTabs card token (token-based transaction).
     *
     * A merchant-initiated charge runs as a `recurring` transaction (requires Recurring
     * mode on the profile); a customer-initiated charge runs as a normal `ecom` one.
     */
    public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult
    {
        $response = $this->client->post(
            PaytabsEndpoint::Request,
            PaytabsPayload::storedCredential($request, $this->gatewayCredentials),
            'charge stored credential',
        );

        return $this->toPaymentResult($response, $request->orderReference ?? $request->paymentInstrumentId);
    }

    /**
     * Capture funds held by a prior PayTabs `auth` authorization.
     *
     * The captured amount is the request's amount, so a smaller amount performs a
     * partial capture. The hold is referenced by its `tran_ref` (the request's
     * transaction id).
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        $response = $this->client->post(
            PaytabsEndpoint::Request,
            PaytabsPayload::capture($request, $this->gatewayCredentials),
            'capture',
        );

        return $this->toPaymentResult($response, $request->transactionId);
    }

    /**
     * Refund all or part of a settled PayTabs transaction by its reference.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->post(
            PaytabsEndpoint::Request,
            PaytabsPayload::refund($request, $this->gatewayCredentials),
            'refund',
        );

        $result = $this->paymentResult($response);
        $respStatus = Value::nullableString($result['response_status'] ?? null);
        $approved = PaytabsResponseStatus::isApproved($respStatus);

        return new RefundResult(
            success: $approved,
            status: $approved ? PaymentStatus::Refunded : PaytabsResponseStatus::toPaymentStatusOrFailed($respStatus),
            refundId: Value::string($response['tran_ref'] ?? $request->transactionId, $request->transactionId),
            code: Value::nullableString($result['response_code'] ?? null),
            message: Value::nullableString($result['response_message'] ?? null),
            raw: $response,
        );
    }

    /**
     * Void (cancel) a PayTabs authorization that has not been captured.
     *
     * Releases the hold placed by an `auth`; once funds are captured/settled use
     * {@see refund()} instead. The hold is referenced by its `tran_ref`.
     */
    public function void(VoidRequest $request): PaymentResult
    {
        $response = $this->client->post(
            PaytabsEndpoint::Request,
            PaytabsPayload::void($request, $this->gatewayCredentials),
            'void',
        );

        return $this->cancelResult($response, PaymentStatus::Voided, $request->transactionId);
    }

    /**
     * Reverse (release) an authorization hold before it is captured.
     *
     * Sends PayTabs' `release` transaction with the reversal amount — a partial amount
     * releases that portion of the hold, the full amount releases all of it. Once funds
     * are captured/settled use {@see refund()} instead.
     */
    public function reverseAuthorization(ReversalRequest $request): PaymentResult
    {
        $response = $this->client->post(
            PaytabsEndpoint::Request,
            PaytabsPayload::release($request, $this->gatewayCredentials),
            'reverse authorization',
        );

        return $this->cancelResult($response, PaymentStatus::Reversed, $request->transactionId);
    }

    /**
     * Look up a transaction's current status via `/payment/query`.
     *
     * @param  string  $transactionId  The PayTabs transaction reference (`tran_ref`).
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        $response = $this->client->post(
            PaytabsEndpoint::Query,
            PaytabsPayload::query($transactionId, $this->gatewayCredentials),
            'get transaction',
        );

        $result = $this->paymentResult($response);
        $respStatus = Value::nullableString($result['response_status'] ?? null);

        return new TransactionSnapshot(
            transactionId: Value::string($response['tran_ref'] ?? $transactionId, $transactionId),
            status: PaytabsResponseStatus::toPaymentStatusOrFailed($respStatus),
            money: $this->money($response),
            orderReference: Value::string($response['cart_id'] ?? $transactionId, $transactionId),
            raw: $response,
        );
    }

    /**
     * Delete (revoke) a saved PayTabs card token.
     *
     * PayTabs returns `code: 0` on success; historical transactions made with the token
     * are unaffected. This PayTabs-specific operation is outside the shared interface.
     *
     * @param  string  $token  The saved card token to revoke.
     * @return bool Whether PayTabs confirmed the deletion.
     */
    public function deleteToken(string $token): bool
    {
        $response = $this->client->post(
            PaytabsEndpoint::TokenDelete,
            PaytabsPayload::deleteToken($token, $this->gatewayCredentials),
            'delete token',
        );

        if (Value::int($response['code'] ?? 1) === 0) {
            return true;
        }

        return ($response['success'] ?? false) === true;
    }

    /**
     * Verify a PayTabs IPN/return callback and parse it into an event.
     *
     * PayTabs posts the callback as URL-encoded form fields (`respStatus`, `tranRef`,
     * `cartId`, `token`, …) with the HMAC-SHA256 signature in a `signature` field, so
     * the supplied headers are unused. The signature is recomputed over the sorted,
     * URL-encoded fields (see {@see PaytabsSignature}) with the merchant server key.
     * When tokenisation was requested, the saved card token is in the payload's `token`
     * field. There is no timestamp, so pair verification with your own idempotency on
     * the transaction reference.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $fields = $this->parseCallback($rawBody);

        $serverKey = $this->gatewayCredentials->webhookSecret ?? $this->gatewayCredentials->sharedSecret;
        $respStatus = Value::nullableString($fields['respStatus'] ?? null);

        return new WebhookEvent(
            verified: PaytabsSignature::verify($fields, $serverKey),
            eventType: Value::nullableString($fields['tranType'] ?? $fields['respStatus'] ?? null),
            transactionId: Value::nullableString($fields['tranRef'] ?? null),
            status: filled($respStatus) ? PaytabsResponseStatus::toPaymentStatusOrFailed($respStatus) : null,
            payload: $fields,
        );
    }

    /**
     * POST a checkout body and normalise the response into a CheckoutSession.
     *
     * Handles all payer-not-present flows: the redirect URL is the hosted `redirect_url`,
     * the `invoice_link`, or the PayLink `link_url`; the reference is the `tran_ref`,
     * `invoice_id`, or `link_id`.
     *
     * @param  array<string, mixed>  $body
     */
    private function checkout(PaytabsEndpoint $endpoint, array $body, CheckoutSessionRequest $request): CheckoutSession
    {
        $response = $this->client->post($endpoint, $body, 'create checkout session');

        return new CheckoutSession(
            redirectUrl: Value::nullableString($response['redirect_url'] ?? $response['invoice_link'] ?? $response['link_url'] ?? null),
            reference: Value::nullableString($response['tran_ref'] ?? $response['invoice_id'] ?? $response['link_id'] ?? null),
            merchantReference: Value::nullableString($response['cart_id'] ?? null) ?? $request->orderReference,
            raw: $response,
        );
    }

    /**
     * Map a PayTabs transaction response (payment_result, or a 3DS redirect) to a result.
     *
     * A present `payment_result` drives the status; when it is absent but a
     * `redirect_url` is (3-D Secure), the charge is Pending pending authentication.
     *
     * @param  array<string, mixed>  $response
     * @param  string|null  $fallbackId  Transaction id to report when the response omits `tran_ref`.
     */
    private function toPaymentResult(array $response, ?string $fallbackId): PaymentResult
    {
        $result = $this->paymentResult($response);
        $respStatus = Value::nullableString($result['response_status'] ?? null);
        $pendingAuth = $respStatus === null && Value::nullableString($response['redirect_url'] ?? null) !== null;

        $status = $respStatus !== null
            ? PaytabsResponseStatus::toPaymentStatusOrFailed($respStatus)
            : ($pendingAuth ? PaymentStatus::Pending : PaymentStatus::Failed);

        return new PaymentResult(
            success: $respStatus !== null ? PaytabsResponseStatus::isApproved($respStatus) : $pendingAuth,
            status: $status,
            transactionId: Value::nullableString($response['tran_ref'] ?? null) ?? $fallbackId,
            code: Value::nullableString($result['response_code'] ?? null),
            message: Value::nullableString($result['response_message'] ?? null),
            raw: $response,
        );
    }

    /**
     * Map a PayTabs void/release response to a result with the given success status.
     *
     * A void or release is treated as successful when PayTabs approves it
     * (response_status A/H) or reports the transaction voided (V); declines, errors, and
     * expiries are failures. The reported status is `$successStatus` (Voided or Reversed).
     *
     * @param  array<string, mixed>  $response
     */
    private function cancelResult(array $response, PaymentStatus $successStatus, string $transactionId): PaymentResult
    {
        $result = $this->paymentResult($response);
        $mapped = PaytabsResponseStatus::toPaymentStatusOrFailed(Value::nullableString($result['response_status'] ?? null));
        $succeeded = in_array($mapped, [PaymentStatus::Voided, PaymentStatus::Captured, PaymentStatus::Authorized], true);

        return new PaymentResult(
            success: $succeeded,
            status: $succeeded ? $successStatus : $mapped,
            transactionId: Value::string($response['tran_ref'] ?? $transactionId, $transactionId),
            code: Value::nullableString($result['response_code'] ?? null),
            message: Value::nullableString($result['response_message'] ?? null),
            raw: $response,
        );
    }

    /**
     * Extract PayTabs' payment_result object from a transaction response.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function paymentResult(array $response): array
    {
        return Value::array($response['payment_result'] ?? []);
    }

    /**
     * Build a Money value object from a query response's cart amount and currency.
     *
     * Returns null when either field is absent or the amount is not a plain decimal.
     *
     * @param  array<string, mixed>  $response
     */
    private function money(array $response): ?Money
    {
        $amount = Value::nullableString($response['cart_amount'] ?? null);
        $currency = Value::nullableString($response['cart_currency'] ?? null);

        if ($amount === null || $currency === null || preg_match('/^\d+(\.\d+)?$/', $amount) !== 1) {
            return null;
        }

        return Money::fromDecimalString($amount, $currency);
    }

    /**
     * Parse a PayTabs callback body (URL-encoded form, or JSON) into its fields.
     *
     * Keys are normalised to strings — PayTabs' callback field names are strings, but
     * PHP would coerce a numeric-looking field name to an int array key.
     *
     * @return array<string, mixed>
     */
    private function parseCallback(string $rawBody): array
    {
        $trimmed = ltrim($rawBody);

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = Value::array(json_decode($rawBody, true));
        } else {
            $parsed = [];
            parse_str($rawBody, $parsed);
            $decoded = $parsed;
        }

        $fields = [];

        foreach ($decoded as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }
}
