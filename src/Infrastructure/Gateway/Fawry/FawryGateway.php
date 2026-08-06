<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry;

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
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Enums\FawryEndpoint;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Enums\FawryOrderStatus;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Enums\FawryPaymentMethod;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads\FawryCancelPayload;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads\FawryCapturePayload;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads\FawryChargePayload;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads\FawryFields;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads\FawryHostedPayload;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads\FawryRefundPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * FawryPay payment gateway adapter.
 *
 * Supports the operations that map onto FawryPay's rails: starting a payment via
 * {@see createCheckoutSession()} (hosted Express Checkout, card 3-D Secure, mobile
 * wallet, pay-at-outlet by reference number, the MyFawry app, or a bank card
 * instalment), capturing an Auth/Capture authorization ({@see capture()}), refunding,
 * cancelling an authorization ({@see void()}), looking up a transaction (Get Payment
 * Status V2), and verifying Server Notification V2 webhooks. Reversal, payer-auth, and
 * vaulting are not part of FawryPay's model and therefore inherit
 * {@see AbstractPaymentGateway}'s unsupported-operation behaviour.
 *
 * All requests are built deterministically from the caller's inputs — the merchant
 * reference is the caller's order reference with no random or time suffix — so a
 * retried request is byte-for-byte identical and FawryPay handles it idempotently.
 */
final class FawryGateway extends AbstractPaymentGateway
{
    private readonly FawryClient $client;

    /**
     * Construct the gateway, wiring a FawryClient from the credentials and HTTP port.
     */
    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);

        $this->client = new FawryClient($http, $credentials);
    }

    /**
     * Identify this driver as the FawryPay gateway.
     */
    public function name(): GatewayName
    {
        return GatewayName::Fawry;
    }

    /**
     * Start a FawryPay payment using the method selected on the request.
     *
     * Routes to the hosted Express Checkout page, a card 3-D Secure charge, a mobile
     * wallet charge, a pay-at-outlet reference-number charge, a MyFawry app charge, or a
     * bank card instalment, and normalises the result into a {@see CheckoutSession}
     * (redirect URL, reference, and/or QR code).
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        return match (FawryPaymentMethod::fromRequest($request->paymentMethod)) {
            FawryPaymentMethod::Hosted => $this->hostedCheckout($request),
            FawryPaymentMethod::Card => $this->cardCheckout($request),
            FawryPaymentMethod::MobileWallet => $this->walletCheckout($request),
            FawryPaymentMethod::PayAtFawry => $this->referenceCheckout($request),
            FawryPaymentMethod::MyFawry => $this->myFawryCheckout($request),
            FawryPaymentMethod::CardInstallment => $this->installmentCheckout($request),
        };
    }

    /**
     * Capture funds held by a prior FawryPay Auth/Capture authorization.
     *
     * The captured amount is the request's amount in FawryPay's two-decimal format, so a
     * smaller amount performs a partial capture. The authorization is referenced by its
     * merchant reference number (the request's order reference, falling back to its
     * transaction id).
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        $response = $this->client->postJson(
            FawryEndpoint::PaymentCapture,
            FawryCapturePayload::build($request, $this->gatewayCredentials),
            'capture',
        );

        return $this->toStatusResult($response, PaymentStatus::Captured, $request->orderReference ?? $request->transactionId);
    }

    /**
     * Refund all or part of a settled FawryPay payment by its reference number.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->postJson(
            FawryEndpoint::Refund,
            FawryRefundPayload::build($request, $this->gatewayCredentials),
            'refund',
        );

        $succeeded = $this->fawryStatusCode($response) === '200';

        return new RefundResult(
            success: $succeeded,
            status: $succeeded ? PaymentStatus::Refunded : PaymentStatus::Failed,
            refundId: $request->transactionId,
            code: $this->fawryStatusCode($response),
            message: Value::nullableString($response['statusDescription'] ?? null),
            raw: $response,
        );
    }

    /**
     * Void (cancel) a FawryPay Auth/Capture authorization that has not been captured.
     *
     * Releases the hold placed by a charge sent with `authCaptureModePayment: true`. The
     * authorization is referenced by its merchant reference number (the request's order
     * reference, falling back to its transaction id). Once funds are captured/settled,
     * use {@see refund()} instead.
     */
    public function void(VoidRequest $request): PaymentResult
    {
        $response = $this->client->postJson(
            FawryEndpoint::PaymentCancel,
            FawryCancelPayload::build($request, $this->gatewayCredentials),
            'void',
        );

        return $this->toStatusResult($response, PaymentStatus::Voided, $request->orderReference ?? $request->transactionId);
    }

    /**
     * Look up a transaction's current status via Get Payment Status V2.
     *
     * @param  string  $transactionId  The merchant reference number used when starting the payment.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        $signature = FawrySignature::status(
            $this->gatewayCredentials->merchantId,
            $transactionId,
            $this->gatewayCredentials->sharedSecret,
        );

        $response = $this->client->getJson(
            FawryEndpoint::StatusV2,
            [
                'merchantCode' => $this->gatewayCredentials->merchantId,
                'merchantRefNumber' => $transactionId,
                'signature' => $signature,
            ],
            'get transaction',
        );

        $status = FawryOrderStatus::toPaymentStatusOrFailed(
            Value::nullableString($response['orderStatus'] ?? $response['paymentStatus'] ?? null),
        );

        return new TransactionSnapshot(
            transactionId: Value::string($response['fawryRefNumber'] ?? $transactionId),
            status: $status,
            orderReference: Value::string($response['merchantRefNumber'] ?? $transactionId),
            raw: $response,
        );
    }

    /**
     * Verify a FawryPay Server Notification V2 webhook and parse it into an event.
     *
     * The signature is the `messageSignature` field inside the JSON body (not a header),
     * so the supplied headers are unused. Idempotency is the consumer's responsibility:
     * dedupe on the returned transaction id, since FawryPay may deliver a notification
     * more than once.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $decoded = json_decode($rawBody, true);
        $payload = Value::array($decoded);

        $expected = FawrySignature::webhook(
            Value::string($payload['fawryRefNumber'] ?? null),
            Value::string($payload['merchantRefNumber'] ?? null),
            FawrySignature::amount(Value::string($payload['paymentAmount'] ?? 0)),
            FawrySignature::amount(Value::string($payload['orderAmount'] ?? 0)),
            Value::string($payload['orderStatus'] ?? null),
            Value::string($payload['paymentMethod'] ?? null),
            $this->paymentReferenceNumber($payload),
            $this->gatewayCredentials->sharedSecret,
        );

        $provided = Value::string($payload['messageSignature'] ?? null);
        $verified = filled($provided) && hash_equals($expected, $provided);

        $orderStatus = Value::nullableString($payload['orderStatus'] ?? null);

        return new WebhookEvent(
            verified: $verified,
            eventType: Value::nullableString($payload['orderStatus'] ?? $payload['type'] ?? null),
            transactionId: Value::nullableString($payload['fawryRefNumber'] ?? null),
            status: filled($orderStatus)
                ? FawryOrderStatus::toPaymentStatusOrFailed($orderStatus)
                : null,
            payload: $payload,
        );
    }

    private function hostedCheckout(CheckoutSessionRequest $request): CheckoutSession
    {
        $url = trim($this->client->postForBody(
            FawryEndpoint::HostedInit,
            FawryHostedPayload::build($request, $this->gatewayCredentials),
            'create hosted checkout',
        ));

        return new CheckoutSession(
            redirectUrl: $url,
            merchantReference: FawryFields::merchantRefNum($request),
            raw: ['checkoutUrl' => $url],
        );
    }

    private function cardCheckout(CheckoutSessionRequest $request): CheckoutSession
    {
        $response = $this->sendCharge(FawryChargePayload::card($request, $this->gatewayCredentials));

        return new CheckoutSession(
            redirectUrl: Value::nullableString(data_get($response, 'nextAction.redirectUrl')),
            reference: isset($response['referenceNumber']) ? Value::string($response['referenceNumber']) : null,
            merchantReference: FawryFields::merchantRefNum($request),
            raw: $response,
        );
    }

    private function walletCheckout(CheckoutSessionRequest $request): CheckoutSession
    {
        $response = $this->sendCharge(FawryChargePayload::wallet($request, $this->gatewayCredentials));

        return new CheckoutSession(
            reference: isset($response['referenceNumber']) ? Value::string($response['referenceNumber']) : null,
            qrCode: Value::nullableString($response['walletQr'] ?? null),
            merchantReference: FawryFields::merchantRefNum($request),
            raw: $response,
        );
    }

    private function referenceCheckout(CheckoutSessionRequest $request): CheckoutSession
    {
        $response = $this->sendCharge(FawryChargePayload::reference($request, $this->gatewayCredentials));

        return new CheckoutSession(
            reference: isset($response['referenceNumber']) ? Value::string($response['referenceNumber']) : null,
            merchantReference: FawryFields::merchantRefNum($request),
            raw: $response,
        );
    }

    private function myFawryCheckout(CheckoutSessionRequest $request): CheckoutSession
    {
        $response = $this->sendCharge(FawryChargePayload::myFawry($request, $this->gatewayCredentials));

        return new CheckoutSession(
            redirectUrl: Value::nullableString($response['deepLink'] ?? null),
            reference: isset($response['referenceNumber']) ? Value::string($response['referenceNumber']) : null,
            qrCode: Value::nullableString($response['qrCode'] ?? null),
            merchantReference: FawryFields::merchantRefNum($request),
            raw: $response,
        );
    }

    private function installmentCheckout(CheckoutSessionRequest $request): CheckoutSession
    {
        $response = $this->sendCharge(FawryChargePayload::installment($request, $this->gatewayCredentials));

        return new CheckoutSession(
            redirectUrl: Value::nullableString(data_get($response, 'nextAction.redirectUrl')),
            reference: isset($response['referenceNumber']) ? Value::string($response['referenceNumber']) : null,
            merchantReference: FawryFields::merchantRefNum($request),
            raw: $response,
        );
    }

    /**
     * Map a FawryPay statusCode-envelope response (capture/void) into a PaymentResult.
     *
     * @param  array<string, mixed>  $response  The decoded FawryPay response body.
     * @param  PaymentStatus  $successStatus  The status to report when FawryPay returns statusCode 200.
     * @param  string  $transactionId  The merchant reference the operation acted on.
     */
    private function toStatusResult(array $response, PaymentStatus $successStatus, string $transactionId): PaymentResult
    {
        $succeeded = $this->fawryStatusCode($response) === '200';

        return new PaymentResult(
            success: $succeeded,
            status: $succeeded ? $successStatus : PaymentStatus::Failed,
            transactionId: $transactionId,
            code: $this->fawryStatusCode($response),
            message: Value::nullableString($response['statusDescription'] ?? null),
            raw: $response,
        );
    }

    /**
     * POST a charge body and assert FawryPay accepted it (body statusCode 200).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function sendCharge(array $body): array
    {
        $response = $this->client->postJson(FawryEndpoint::Charge, $body, 'charge');

        if ($this->fawryStatusCode($response) !== '200') {
            throw new GatewayRequestException(
                status: (int) $this->fawryStatusCode($response),
                responseBody: (string) json_encode($response),
                response: $response,
                message: 'FawryPay charge failed: '.Value::string($response['statusDescription'] ?? 'unknown error', 'unknown error'),
            );
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fawryStatusCode(array $response): string
    {
        return Value::string($response['statusCode'] ?? null);
    }

    /**
     * Resolve FawryPay's payment reference number, tolerating its documented misspelling.
     *
     * @param  array<string, mixed>  $payload
     */
    private function paymentReferenceNumber(array $payload): ?string
    {
        $reference = $payload['paymentRefrenceNumber'] ?? $payload['paymentReferenceNumber'] ?? null;

        return Value::nullableString($reference);
    }
}
