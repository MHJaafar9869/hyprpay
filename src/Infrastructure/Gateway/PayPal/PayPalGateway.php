<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
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
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalEndpoint;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalOrderStatus;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalPaymentStatus;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Payloads\PayPalPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * PayPal REST (Orders v2 / Payments v2) payment gateway adapter.
 *
 * Drives PayPal's standard wallet checkout: {@see createCheckoutSession()} creates an
 * order and returns the buyer-approval redirect URL, then — once the buyer approves —
 * {@see charge()} completes that order (capturing immediately, or authorizing a hold when
 * the charge asks to authorize only), keyed by the order id carried in the request's
 * transient token. Follow-ons act on the resulting payment resources: {@see capture()} and
 * {@see void()} on an authorization id, {@see refund()} on a capture id. Cards are vaulted
 * with {@see vaultInstrument()} (setup-token → payment-token) and charged card-on-file via
 * {@see chargeStoredCredential()}; {@see getTransaction()} reads an order back, and
 * {@see verifyWebhook()} validates a notification through PayPal's verify-signature API.
 * Payer-auth and DCC are not part of this driver and inherit {@see AbstractPaymentGateway}'s
 * unsupported behaviour.
 *
 * Requests are built deterministically from the caller's inputs, and idempotent operations
 * carry a `PayPal-Request-Id` derived from the caller's idempotency key or order reference,
 * so a retried request is deduplicated by PayPal.
 */
final class PayPalGateway extends AbstractPaymentGateway
{
    private readonly PayPalClient $client;

    /**
     * Construct the gateway, wiring a PayPalClient from the credentials and HTTP port.
     */
    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);

        $this->client = new PayPalClient($http, $credentials);
    }

    /**
     * Identify this driver as the PayPal gateway.
     */
    public function name(): GatewayName
    {
        return GatewayName::PayPal;
    }

    /**
     * Create a PayPal order and return the buyer-approval redirect URL.
     *
     * The order captures on approval unless `paymentMethod` is `authorize`, which places a
     * hold to capture later. The returned session's `redirectUrl` is PayPal's approval link
     * (the `payer-action`/`approve` HATEOAS link) and `reference` is the order id — pass it
     * as {@see charge()}'s transient token after the buyer approves.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $response = $this->client->post(
            PayPalEndpoint::Orders,
            PayPalPayload::order($request),
            'create checkout session',
            '',
            $this->idempotencyHeader($request->orderReference),
        );

        return new CheckoutSession(
            redirectUrl: $this->approvalUrl($response),
            reference: Value::nullableString($response['id'] ?? null),
            merchantReference: $this->orderReference($response) ?? $request->orderReference,
            raw: $response,
        );
    }

    /**
     * Complete an approved PayPal order, capturing it or placing an authorization hold.
     *
     * The request's transient token is the order id returned by {@see createCheckoutSession()}.
     * When `capture` is true the order is captured and the result reports the capture id;
     * otherwise the order is authorized and the result reports the authorization id (to feed
     * {@see capture()}/{@see void()} later).
     */
    public function charge(ChargeRequest $request): PaymentResult
    {
        $orderId = $request->transientToken;
        $headers = $this->idempotencyHeader($request->idempotencyKey ?? $request->orderReference);

        if ($request->capture) {
            $order = $this->client->post(PayPalEndpoint::OrderCapture, null, 'capture order', $orderId, $headers);

            return $this->orderPaymentResult($order, 'captures', PaymentStatus::Captured);
        }

        $order = $this->client->post(PayPalEndpoint::OrderAuthorize, null, 'authorize order', $orderId, $headers);

        return $this->orderPaymentResult($order, 'authorizations', PaymentStatus::Authorized);
    }

    /**
     * Capture funds on a previously authorized payment.
     *
     * The captured amount is the request's amount, so a smaller amount performs a partial
     * capture. The authorization is referenced by its id (the request's transaction id) and
     * the result reports the resulting capture id.
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        $response = $this->client->post(
            PayPalEndpoint::AuthorizationCapture,
            PayPalPayload::captureAuthorization($request),
            'capture',
            $request->transactionId,
            $this->idempotencyHeader($request->idempotencyKey ?? $request->orderReference),
        );

        return $this->resourceResult($response, $request->transactionId, PaymentStatus::Captured);
    }

    /**
     * Refund all or part of a captured payment.
     *
     * The capture is referenced by its id (the request's transaction id); a smaller amount
     * performs a partial refund.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->post(
            PayPalEndpoint::CaptureRefund,
            PayPalPayload::refundCapture($request),
            'refund',
            $request->transactionId,
            $this->idempotencyHeader($request->idempotencyKey ?? $request->orderReference),
        );

        $status = PayPalPaymentStatus::toPaymentStatusOrFailed(
            Value::nullableString($response['status'] ?? null),
            PaymentStatus::Refunded,
        );

        return new RefundResult(
            success: in_array($status, [PaymentStatus::Refunded, PaymentStatus::Pending], true),
            status: $status,
            refundId: Value::nullableString($response['id'] ?? null) ?? $request->transactionId,
            code: Value::nullableString($response['status'] ?? null),
            message: Value::nullableString(data_get($response, 'status_details.reason')),
            raw: $response,
        );
    }

    /**
     * Void an authorized-but-uncaptured payment, releasing the held funds.
     *
     * The authorization is referenced by its id. PayPal returns no content on success, so
     * the result reports the voided status; once funds are captured use {@see refund()}.
     */
    public function void(VoidRequest $request): PaymentResult
    {
        $response = $this->client->post(
            PayPalEndpoint::AuthorizationVoid,
            null,
            'void',
            $request->transactionId,
            $this->idempotencyHeader($request->idempotencyKey ?? $request->orderReference),
        );

        return new PaymentResult(
            success: true,
            status: PaymentStatus::Voided,
            transactionId: Value::nullableString($response['id'] ?? null) ?? $request->transactionId,
            code: Value::nullableString($response['status'] ?? null),
            raw: $response,
        );
    }

    /**
     * Vault a raw card for later reuse via PayPal's two-step token flow.
     *
     * Creates a setup token from the card, then exchanges it for a permanent payment token.
     * The returned instrument's `paymentInstrumentId` is the vault id to charge through
     * {@see chargeStoredCredential()}, and `customerId` is the PayPal-assigned vault customer.
     */
    public function vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument
    {
        $setupToken = $this->client->post(
            PayPalEndpoint::SetupTokens,
            PayPalPayload::setupToken($request),
            'create setup token',
        );

        $paymentToken = $this->client->post(
            PayPalEndpoint::PaymentTokens,
            PayPalPayload::paymentToken(Value::string($setupToken['id'] ?? null)),
            'create payment token',
        );

        $paymentTokenId = Value::nullableString($paymentToken['id'] ?? null);

        return new VaultedInstrument(
            success: $paymentTokenId !== null,
            customerId: Value::nullableString(data_get($paymentToken, 'customer.id')),
            paymentInstrumentId: $paymentTokenId,
            raw: $paymentToken,
        );
    }

    /**
     * Charge a vaulted card (stored credential), honoring CIT/MIT rules.
     *
     * Creates an order that references the saved card by its vault id with the network
     * stored-credential metadata, then captures it. When PayPal completes the order inline
     * the extra capture call is skipped.
     */
    public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult
    {
        $order = $this->client->post(
            PayPalEndpoint::Orders,
            PayPalPayload::storedCredentialOrder($request),
            'create stored credential order',
            '',
            $this->idempotencyHeader($request->idempotencyKey ?? $request->orderReference),
        );

        if (Value::nullableString($order['status'] ?? null) === PayPalOrderStatus::Completed->value) {
            return $this->orderPaymentResult($order, 'captures', PaymentStatus::Captured);
        }

        $captured = $this->client->post(
            PayPalEndpoint::OrderCapture,
            null,
            'capture stored credential order',
            Value::string($order['id'] ?? null),
        );

        return $this->orderPaymentResult($captured, 'captures', PaymentStatus::Captured);
    }

    /**
     * Look up an order's current status via `GET /v2/checkout/orders/{id}`.
     *
     * @param  string  $transactionId  The PayPal order id returned when the checkout was created.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        $response = $this->client->get(PayPalEndpoint::Order, $transactionId, 'get transaction');

        return new TransactionSnapshot(
            transactionId: Value::string($response['id'] ?? $transactionId, $transactionId),
            status: PayPalOrderStatus::toPaymentStatusOrFailed(Value::nullableString($response['status'] ?? null)),
            money: $this->money($response),
            orderReference: $this->orderReference($response) ?? $transactionId,
            raw: $response,
        );
    }

    /**
     * Verify a PayPal webhook notification and parse it into an event.
     *
     * PayPal does not sign the body with a shared secret; instead the transmission metadata
     * arrives in the `PayPal-Transmission-*` / `PayPal-Cert-Url` / `PayPal-Auth-Algo`
     * headers, which — together with the configured `webhook_id` (the credentials' webhook
     * secret) — are POSTed to PayPal's verify-signature API. The event's affected resource
     * id and status are read from the notification body.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $event = Value::array(json_decode($rawBody, true));
        $webhookId = Value::string($this->gatewayCredentials->webhookSecret ?? null);

        $response = $this->client->post(
            PayPalEndpoint::VerifyWebhookSignature,
            PayPalPayload::verifyWebhookSignature($webhookId, $headers, $event),
            'verify webhook',
        );

        $resource = Value::array($event['resource'] ?? []);
        $resourceStatus = Value::nullableString($resource['status'] ?? null);

        return new WebhookEvent(
            verified: strtoupper(Value::string($response['verification_status'] ?? null)) === 'SUCCESS',
            eventType: Value::nullableString($event['event_type'] ?? null),
            transactionId: Value::nullableString($resource['id'] ?? null),
            status: $resourceStatus !== null ? PayPalPaymentStatus::toPaymentStatusOrFailed($resourceStatus) : null,
            payload: $event,
        );
    }

    /**
     * Map a capture/authorize-order response to a result via its nested payment resource.
     *
     * Reads the first capture or authorization under `purchase_units[].payments`, falling
     * back to the order-level status when the nested resource is absent.
     *
     * @param  array<string, mixed>  $order
     * @param  string  $collection  The payments sub-collection to read (`captures` or `authorizations`).
     */
    private function orderPaymentResult(array $order, string $collection, PaymentStatus $completedAs): PaymentResult
    {
        $resource = $this->firstPaymentResource($order, $collection);
        $resourceStatus = Value::nullableString($resource['status'] ?? null);

        $status = $resourceStatus !== null
            ? PayPalPaymentStatus::toPaymentStatusOrFailed($resourceStatus, $completedAs)
            : PayPalOrderStatus::toPaymentStatusOrFailed(Value::nullableString($order['status'] ?? null));

        return new PaymentResult(
            success: $status->isSuccessful(),
            status: $status,
            transactionId: Value::nullableString($resource['id'] ?? null) ?? Value::nullableString($order['id'] ?? null),
            code: $resourceStatus ?? Value::nullableString($order['status'] ?? null),
            message: Value::nullableString(data_get($resource, 'status_details.reason')),
            raw: $order,
        );
    }

    /**
     * Map a standalone capture response to a result.
     *
     * @param  array<string, mixed>  $resource
     */
    private function resourceResult(array $resource, ?string $fallbackId, PaymentStatus $completedAs): PaymentResult
    {
        $resourceStatus = Value::nullableString($resource['status'] ?? null);
        $status = PayPalPaymentStatus::toPaymentStatusOrFailed($resourceStatus, $completedAs);

        return new PaymentResult(
            success: $status->isSuccessful(),
            status: $status,
            transactionId: Value::nullableString($resource['id'] ?? null) ?? $fallbackId,
            code: $resourceStatus,
            message: Value::nullableString(data_get($resource, 'status_details.reason')),
            raw: $resource,
        );
    }

    /**
     * Extract the first capture/authorization object from an order's purchase units.
     *
     * @param  array<string, mixed>  $order
     * @param  string  $collection  The payments sub-collection to read (`captures` or `authorizations`).
     * @return array<string, mixed>
     */
    private function firstPaymentResource(array $order, string $collection): array
    {
        $unit = $this->firstPurchaseUnit($order);
        $resources = data_get($unit, "payments.{$collection}");

        return is_array($resources) && isset($resources[0]) && is_array($resources[0])
            ? Value::array($resources[0])
            : [];
    }

    /**
     * Resolve the PayPal buyer-approval URL from the order's HATEOAS links.
     *
     * Prefers the `payer-action` link (the current checkout redirect), falling back to the
     * classic `approve` link.
     *
     * @param  array<string, mixed>  $response
     */
    private function approvalUrl(array $response): ?string
    {
        $links = $response['links'] ?? [];

        if (! is_array($links)) {
            return null;
        }

        $byRel = [];

        foreach ($links as $link) {
            if (is_array($link) && isset($link['rel'], $link['href'])) {
                $byRel[Value::string($link['rel'])] = Value::nullableString($link['href']);
            }
        }

        return $byRel['payer-action'] ?? $byRel['approve'] ?? null;
    }

    /**
     * Read the merchant order reference (`custom_id`) from the first purchase unit.
     *
     * @param  array<string, mixed>  $response
     */
    private function orderReference(array $response): ?string
    {
        return Value::nullableString(data_get($this->firstPurchaseUnit($response), 'custom_id'));
    }

    /**
     * Build a Money value object from the first purchase unit's amount.
     *
     * Returns null when the amount is absent or is not a plain decimal.
     *
     * @param  array<string, mixed>  $response
     */
    private function money(array $response): ?Money
    {
        $unit = $this->firstPurchaseUnit($response);
        $value = Value::nullableString(data_get($unit, 'amount.value'));
        $currency = Value::nullableString(data_get($unit, 'amount.currency_code'));

        if ($value === null || $currency === null || preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        return Money::fromDecimalString($value, $currency);
    }

    /**
     * Extract the first purchase unit from an order response.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function firstPurchaseUnit(array $response): array
    {
        $units = $response['purchase_units'] ?? [];

        return is_array($units) && isset($units[0]) && is_array($units[0]) ? Value::array($units[0]) : [];
    }

    /**
     * Build the PayPal idempotency header when a key is available.
     *
     * @return array<string, string>
     */
    private function idempotencyHeader(?string $key): array
    {
        return filled($key) ? ['PayPal-Request-Id' => Value::string($key)] : [];
    }
}
