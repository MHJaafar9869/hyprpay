<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Concerns\MapsMpgsResponses;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Concerns\ResolvesMpgsIdentifiers;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Concerns\VerifiesMpgsWebhook;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsEndpoint;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsOrderStatus;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads\CapturePayload;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads\ChargePayload;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads\HostedCheckoutPayload;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads\PayerAuthenticatePayload;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads\PayerAuthInitiatePayload;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads\RefundPayload;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads\StoredCredentialPayload;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads\TokenizePayload;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads\VoidPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Mastercard Payment Gateway Services (MPGS) payment gateway adapter.
 *
 * Implements every operation MPGS supports through this SDK: hosted checkout session
 * creation, session-based charge (PAY/AUTHORIZE), capture, refund, void, authorization
 * reversal, 3-D Secure payer-auth initiate/authenticate, card tokenisation and
 * stored-credential charges, order retrieval/search, and webhook verification. Requests
 * are built by the Payloads/* helpers, sent through MpgsClient, and mapped back into SDK
 * result DTOs by the MapsMpgsResponses concern; identifiers and webhook checks come from
 * the ResolvesMpgsIdentifiers and VerifiesMpgsWebhook concerns.
 *
 * MPGS keys payments on a merchant-assigned order id plus a per-order transaction id, so
 * this driver adopts a fixed mapping from the SDK's single-id request DTOs: the order id
 * comes from a request's `orderReference` (generated for order-creating operations when
 * absent, required for operations that act on an existing order); a newly-created
 * transaction reuses the request's `idempotencyKey` (re-PUTting the same order+transaction
 * id is MPGS's native idempotency); a void/reversal targets the request's `transactionId`
 * as the prior transaction to undo; and getTransaction/searchTransaction treat their id as
 * the MPGS order id (the reconciliation unit).
 */
final class MpgsGateway extends AbstractPaymentGateway
{
    use MapsMpgsResponses;
    use ResolvesMpgsIdentifiers;
    use VerifiesMpgsWebhook;

    private readonly MpgsClient $client;

    /**
     * Construct the gateway, wiring an MpgsClient from the given credentials and HTTP
     * client port.
     */
    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);

        $this->client = new MpgsClient($http, $credentials);
    }

    /**
     * Return the gateway's identifying name enum.
     */
    public function name(): GatewayName
    {
        return GatewayName::Mpgs;
    }

    /**
     * Create an MPGS Hosted Checkout session and return it as a CheckoutSession.
     *
     * Posts an INITIATE_CHECKOUT payload for the resolved order id and returns the session
     * id (used to launch the hosted checkout on the front end) alongside the checkout
     * client-library URL.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $orderId = $this->orderId($request->orderReference);

        $response = $this->client->post(
            MpgsEndpoint::Session->path(),
            HostedCheckoutPayload::build($request, $orderId),
            'create checkout session',
        );

        return new CheckoutSession(
            clientLibrary: sprintf('https://%s/static/checkout/checkout.min.js', $this->gatewayCredentials->host),
            reference: Value::nullableString(data_get($response, 'session.id')),
            merchantReference: $orderId,
            raw: $response,
        );
    }

    /**
     * Charge a session-tokenised card via a PAY (capture) or AUTHORIZE transaction,
     * returning the mapped PaymentResult.
     */
    public function charge(ChargeRequest $request): PaymentResult
    {
        return $this->toPaymentResult(
            $this->client->put(
                $this->transactionPath($this->orderId($request->orderReference), $this->newTransactionId($request->idempotencyKey)),
                ChargePayload::build($request),
                'charge',
            ),
            $request->capture ? PaymentStatus::Captured : PaymentStatus::Authorized,
        );
    }

    /**
     * Capture funds against the order's prior authorization, returning the mapped
     * PaymentResult.
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        return $this->toPaymentResult(
            $this->client->put(
                $this->transactionPath($this->requireOrderId($request->orderReference, 'capture'), $this->newTransactionId($request->idempotencyKey)),
                CapturePayload::build($request),
                'capture',
            ),
            PaymentStatus::Captured,
        );
    }

    /**
     * Refund funds against the order's captured payment and map the response into a
     * RefundResult (with a Refunded success status).
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->put(
            $this->transactionPath($this->requireOrderId($request->orderReference, 'refund'), $this->newTransactionId($request->idempotencyKey)),
            RefundPayload::build($request),
            'refund',
        );

        $status = $this->resolveStatus($response, PaymentStatus::Refunded);

        return new RefundResult(
            success: $status->isSuccessful(),
            status: $status,
            refundId: $this->transactionId($response),
            code: $this->gatewayCode($response),
            message: $this->message($response),
            raw: $response,
        );
    }

    /**
     * Void the targeted prior transaction, returning the mapped PaymentResult (with a
     * Voided success status).
     */
    public function void(VoidRequest $request): PaymentResult
    {
        return $this->toPaymentResult(
            $this->client->put(
                $this->transactionPath($this->requireOrderId($request->orderReference, 'void'), $this->newTransactionId($request->idempotencyKey)),
                VoidPayload::build($request),
                'void',
            ),
            PaymentStatus::Voided,
        );
    }

    /**
     * Reverse the targeted authorization (an MPGS VOID against the authorization),
     * returning the mapped PaymentResult (with a Reversed success status).
     */
    public function reverseAuthorization(ReversalRequest $request): PaymentResult
    {
        return $this->toPaymentResult(
            $this->client->put(
                $this->transactionPath($this->requireOrderId($request->orderReference, 'reverse authorization'), $this->newTransactionId($request->idempotencyKey)),
                VoidPayload::forReversal($request),
                'reverse authorization',
            ),
            PaymentStatus::Reversed,
        );
    }

    /**
     * Begin 3-D Secure by initiating payer authentication, returning the mapped
     * PayerAuthResult (whose authentication transaction id is threaded into validation).
     */
    public function enrollPayerAuth(PayerAuthEnrollRequest $request): PayerAuthResult
    {
        return $this->toPayerAuthResult($this->client->put(
            $this->transactionPath($this->orderId($request->orderReference), $this->newTransactionId(null)),
            PayerAuthInitiatePayload::build($request),
            'enroll payer auth',
        ));
    }

    /**
     * Complete 3-D Secure by authenticating the payer on the same order/transaction the
     * enrollment started, returning the mapped PayerAuthResult.
     */
    public function validatePayerAuth(ValidatePayerAuthRequest $request): PayerAuthResult
    {
        return $this->toPayerAuthResult($this->client->put(
            $this->transactionPath($this->orderId($request->orderReference), $request->authenticationTransactionId),
            PayerAuthenticatePayload::build($request),
            'validate payer auth',
        ));
    }

    /**
     * Tokenise a card in the MPGS token repository, returning a VaultedInstrument whose
     * identifiers carry the issued token (success is determined by the token's presence).
     */
    public function vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument
    {
        $response = $this->client->post(
            MpgsEndpoint::Token->path(),
            TokenizePayload::build($request),
            'vault instrument',
        );

        $token = Value::nullableString($response['token'] ?? null);

        return new VaultedInstrument(
            success: $token !== null,
            instrumentIdentifierId: $token,
            paymentInstrumentId: $token,
            raw: $response,
        );
    }

    /**
     * Charge a previously vaulted token as a stored credential (card-on-file), returning
     * the mapped PaymentResult.
     */
    public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult
    {
        return $this->toPaymentResult(
            $this->client->put(
                $this->transactionPath($this->orderId($request->orderReference), $this->newTransactionId($request->idempotencyKey)),
                StoredCredentialPayload::build($request),
                'charge stored credential',
            ),
            PaymentStatus::Captured,
        );
    }

    /**
     * Retrieve an order (the MPGS reconciliation unit) by its id and map it into a
     * TransactionSnapshot.
     *
     * @param  string  $transactionId  The MPGS order id to retrieve.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        return $this->toSnapshot($this->client->get(
            MpgsEndpoint::Order->path(['orderId' => $transactionId]),
            'get transaction',
        ));
    }

    /**
     * Look up an order by its id, returning a TransactionSnapshot or null when no such
     * order exists.
     *
     * @param  string  $query  The MPGS order id to look up.
     */
    public function searchTransaction(string $query): ?TransactionSnapshot
    {
        $response = $this->client->tryGet(MpgsEndpoint::Order->path(['orderId' => $query]));

        if ($response === null || blank($response)) {
            return null;
        }

        return $this->toSnapshot($response);
    }

    /**
     * Verify and parse an inbound MPGS webhook (notification) into a WebhookEvent.
     *
     * Verifies the notification secret against the configured webhook secret, then decodes
     * the body to extract the transaction id and the normalised order status.
     *
     * @param  string  $rawBody  Exact raw request body as received.
     * @param  array<string, string|array<int, string>>  $headers  Inbound request headers.
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $verified = $this->webhookSecretIsValid($headers, $this->gatewayCredentials->webhookSecret);

        $payload = Value::array(json_decode($rawBody, true));
        $order = Value::array($payload['order'] ?? null);
        $status = Value::nullableString($order['status'] ?? $payload['status'] ?? null);

        return new WebhookEvent(
            verified: $verified,
            eventType: Value::nullableString(data_get($payload, 'transaction.type') ?? $order['status'] ?? null),
            transactionId: Value::nullableString(data_get($payload, 'transaction.id') ?? $order['id'] ?? $payload['id'] ?? null),
            status: $status !== null ? MpgsOrderStatus::toPaymentStatusOrFailed($status) : null,
            payload: $payload,
        );
    }

    /**
     * Build the per-transaction endpoint path for an order id and transaction id.
     */
    private function transactionPath(string $orderId, string $transactionId): string
    {
        return MpgsEndpoint::Transaction->path([
            'orderId' => $orderId,
            'transactionId' => $transactionId,
        ]);
    }
}
