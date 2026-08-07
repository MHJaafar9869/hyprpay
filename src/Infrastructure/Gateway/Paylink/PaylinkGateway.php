<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paylink;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Paylink\Enums\PaylinkPaidStatus;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * PayLink Payment Integration gateway adapter (pay.getpayin.com).
 *
 * Lets an integrator accept payments through PayLink itself — the same token +
 * per-request HMAC-signature contract the PayLink WooCommerce plugin and sibling
 * SDKs use. Implements the invoice lifecycle: {@see createCheckoutSession()} (init →
 * hosted checkout URL), {@see capture()} (settle), refund, void, reverse-authorization,
 * {@see getTransaction()} (check-status), and HMAC webhook verification. Card
 * tokenization, VCC, and recurring flows are out of scope here and inherit
 * {@see AbstractPaymentGateway}'s unsupported behaviour.
 *
 * Requests are built deterministically; PayLink also honours an `Idempotency-Key`
 * header, which the driver sets from the request's idempotency key or order reference.
 */
final class PaylinkGateway extends AbstractPaymentGateway
{
    private readonly PaylinkClient $client;

    /**
     * Construct the gateway, wiring a PaylinkClient from the credentials and HTTP port.
     */
    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);

        $this->client = new PaylinkClient($http, $credentials);
    }

    /**
     * Identify this driver as the PayLink gateway.
     */
    public function name(): GatewayName
    {
        return GatewayName::Paylink;
    }

    /**
     * Create a PayLink invoice and return its checkout URL and invoice id.
     *
     * Set `options['iframe'] = true` to have PayLink return an iframe-ready checkout
     * URL (embed it in an <iframe>) instead of a full-page redirect URL. The flag is
     * sent unsigned, mirroring the server, so it never affects the request signature.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $billTo = $request->billTo;
        $customer = $request->customer;
        $customerFirstName = $customer?->firstName;
        $customerLastName = $customer?->lastName;
        $customerEmail = $customer?->email;

        $response = $this->client->post(
            PaylinkEndpoint::InvoiceCreate,
            [
                'first_name' => $customerFirstName ?? $billTo?->firstName,
                'last_name' => $customerLastName ?? $billTo?->lastName,
                'email' => $customerEmail ?? $billTo?->email,
                'order_title' => $request->description ?? 'Payment',
                'order_amount' => $request->money->toDecimalString(),
                'address' => $billTo?->address1,
                'city' => $billTo?->locality,
                'country' => $billTo?->country,
                'state' => $billTo?->administrativeArea,
                'currency' => $request->money->currency,
                'redirection_url' => $request->returnUrl,
                'webhook_url' => $request->options['webhook_url'] ?? null,
                'order_details' => $request->options['order_details'] ?? null,
                'payment_mode' => $request->options['payment_mode'] ?? null,
                'iframe' => filter_var($request->options['iframe'] ?? false, FILTER_VALIDATE_BOOLEAN) ?: null,
            ],
            Value::nullableString($request->options['idempotency_key'] ?? $request->orderReference),
            'create checkout session',
        );

        return new CheckoutSession(
            redirectUrl: Value::nullableString($response['checkout_url'] ?? null),
            reference: Value::nullableString($response['invoice_id'] ?? null),
            merchantReference: $request->orderReference,
            raw: $response,
        );
    }

    /**
     * Capture (settle) an authorized PayLink invoice.
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        return $this->toPaymentResult($this->client->post(
            PaylinkEndpoint::Settle,
            ['invoice_id' => $request->transactionId, 'amount' => $request->money->toDecimalString()],
            $request->idempotencyKey ?? $request->orderReference,
            'capture',
        ));
    }

    /**
     * Refund a PayLink invoice, fully or partially.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->post(
            PaylinkEndpoint::Refund,
            ['invoice_id' => $request->transactionId, 'amount' => $request->money->toDecimalString()],
            $request->idempotencyKey ?? $request->orderReference,
            'refund',
        );

        $status = PaylinkPaidStatus::toPaymentStatus(Value::nullableString($response['paid_status'] ?? null));

        return new RefundResult(
            success: $status->isSuccessful(),
            status: $status,
            refundId: filled($response['invoice_id'] ?? null) ? Value::string($response['invoice_id']) : $request->transactionId,
            code: Value::nullableString($response['auth_code'] ?? null),
            raw: $response,
        );
    }

    /**
     * Void a paid PayLink invoice.
     */
    public function void(VoidRequest $request): PaymentResult
    {
        return $this->toPaymentResult($this->client->post(
            PaylinkEndpoint::Void,
            ['invoice_id' => $request->transactionId],
            $request->idempotencyKey ?? $request->orderReference,
            'void',
        ));
    }

    /**
     * Reverse an authorization hold on a PayLink invoice.
     */
    public function reverseAuthorization(ReversalRequest $request): PaymentResult
    {
        return $this->toPaymentResult($this->client->post(
            PaylinkEndpoint::ReverseAuthorization,
            ['invoice_id' => $request->transactionId],
            $request->idempotencyKey ?? $request->orderReference,
            'reverse authorization',
        ));
    }

    /**
     * Query the current status of a PayLink invoice (check-status).
     *
     * @param  string  $transactionId  The PayLink invoice id.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        $response = $this->client->post(
            PaylinkEndpoint::CheckStatus,
            ['invoice_id' => $transactionId],
            null,
            'get transaction',
        );

        return new TransactionSnapshot(
            transactionId: Value::string($response['invoice_id'] ?? $transactionId),
            status: PaylinkPaidStatus::toPaymentStatus(Value::nullableString($response['paid_status'] ?? null)),
            orderReference: Value::string($response['invoice_id'] ?? $transactionId),
            raw: $response,
        );
    }

    /**
     * Verify a PayLink webhook and parse it into an event.
     *
     * PayLink puts the signature in the body (there is no signature header, so the
     * supplied headers are unused). The signature covers success, invoice_id,
     * invoice_status, and message, plus mandate_id, external_reference, and
     * subscription_status when the payload carries them. There is no timestamp, so
     * pair verification with your own idempotency on the invoice id.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $decoded = json_decode($rawBody, true);
        $payload = Value::array($decoded);

        $hashToken = $this->gatewayCredentials->webhookSecret ?? $this->gatewayCredentials->sharedSecret;
        $expected = $this->webhookSignature($payload, $hashToken);
        $provided = Value::string($payload['signature'] ?? null);

        $invoiceStatus = Value::nullableString($payload['invoice_status'] ?? null);

        return new WebhookEvent(
            verified: filled($provided) && PaylinkSignature::equals($expected, $provided),
            eventType: Value::nullableString($payload['event'] ?? null),
            transactionId: Value::nullableString($payload['invoice_id'] ?? null),
            status: filled($invoiceStatus)
                ? PaylinkPaidStatus::toPaymentStatus($invoiceStatus)
                : null,
            payload: $payload,
        );
    }

    /**
     * Recompute the expected PayLink webhook signature over its ordered fields.
     *
     * @param  array<string, mixed>  $payload
     */
    private function webhookSignature(array $payload, string $hashToken): string
    {
        $values = [
            PaylinkSignature::coerce($payload['success'] ?? null),
            PaylinkSignature::coerce($payload['invoice_id'] ?? null),
            PaylinkSignature::coerce($payload['invoice_status'] ?? null),
            PaylinkSignature::coerce($payload['message'] ?? null),
        ];

        foreach (['mandate_id', 'external_reference', 'subscription_status'] as $key) {
            if (array_key_exists($key, $payload)) {
                $values[] = PaylinkSignature::coerce($payload[$key]);
            }
        }

        return PaylinkSignature::build($values, $hashToken);
    }

    /**
     * Map a PayLink payment response (invoice_id, paid_status, auth_code) to a result.
     *
     * @param  array<string, mixed>  $response
     */
    private function toPaymentResult(array $response): PaymentResult
    {
        $status = PaylinkPaidStatus::toPaymentStatus(Value::nullableString($response['paid_status'] ?? null));

        return new PaymentResult(
            success: $status->isSuccessful(),
            status: $status,
            transactionId: Value::nullableString($response['invoice_id'] ?? null),
            code: Value::nullableString($response['auth_code'] ?? null),
            raw: $response,
        );
    }
}
