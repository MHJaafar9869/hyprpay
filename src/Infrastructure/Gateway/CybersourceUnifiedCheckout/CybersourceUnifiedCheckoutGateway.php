<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\ConfirmOrchestratedPaymentRequest;
use Hyprpay\Payments\Domain\Command\DccRateRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\Result\OrchestratedPaymentResult;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns\ParsesTransientToken;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns\VerifiesCybersourceWebhook;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns\VerifiesResultJwt;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums\CybersourceEndpoint;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums\CybersourceTransactionStatus;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\CaptureContextPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\CapturePayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\CurrencyConversionPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PayerAuthEnrollPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PayerAuthValidatePayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PaymentPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\RefundPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\ReversalPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\SearchPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\StoredCredentialPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\TokenizePayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\VoidPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\WalletPaymentPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * CyberSource Unified Checkout payment gateway adapter.
 *
 * The SDK's first concrete gateway: it implements every operation CyberSource
 * supports (capture-context creation, charge, capture, refund, void, authorization
 * reversal, 3DS payer-auth enroll/validate, instrument vaulting, stored-credential
 * charges, transaction lookup/search, and webhook verification). Requests are built
 * by the Payloads/* helpers, sent through CybersourceClient, and the JSON responses
 * are mapped back into SDK result DTOs by the private mapper methods. Transient-token
 * decoding and webhook signature checks come from the ParsesTransientToken and
 * VerifiesCybersourceWebhook concerns.
 */
final class CybersourceUnifiedCheckoutGateway extends AbstractPaymentGateway
{
    use ParsesTransientToken;
    use VerifiesCybersourceWebhook;
    use VerifiesResultJwt;

    private readonly CybersourceClient $client;

    /**
     * Constructs the gateway, wiring a CybersourceClient from the given credentials
     * and HTTP client port.
     */
    public function __construct(GatewayCredentials $credentials, HttpClient $http)
    {
        parent::__construct($credentials);

        $this->client = new CybersourceClient($http, $credentials);
    }

    /**
     * Returns the gateway's identifying name enum.
     */
    public function name(): GatewayName
    {
        return GatewayName::CybersourceUnifiedCheckout;
    }

    /**
     * Creates a Unified Checkout capture context and returns it as a CheckoutSession.
     *
     * Posts a capture-context payload, receives a bare JWT, decodes its claims to
     * extract the embedded client-library URL and integrity hash, and returns the
     * JWT plus those front-end bootstrap values.
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $jwt = trim($this->client->postForBody(
            CybersourceEndpoint::CaptureContexts->path(),
            CaptureContextPayload::build($request, $this->gatewayCredentials),
            'create checkout session',
        ));

        $claims = $this->decodeJwtClaims($jwt) ?? [];
        $context = Value::array(data_get($claims, 'ctx.0.data'));

        return new CheckoutSession(
            jwt: $jwt,
            clientLibrary: Value::nullableString($context['clientLibrary'] ?? null),
            clientLibraryIntegrity: Value::nullableString($context['clientLibraryIntegrity'] ?? null),
            raw: $claims,
        );
    }

    /**
     * Confirms a Unified Checkout v1 orchestrated (autoProcessing) payment from its result JWT.
     *
     * Sources the RS256 verification key from the capture context's embedded flx.jwk,
     * cryptographically verifies the completed-payment result JWT (signature and lifetime),
     * validates it against the request (issuer, order reference, amount and currency), and
     * maps the verified claims — including the reusable TMS token for later stored-credential
     * charges — into an OrchestratedPaymentResult. No server-side authorization and no
     * transaction-search call is made: the signed result is trusted once verified.
     */
    public function confirmOrchestratedPayment(ConfirmOrchestratedPaymentRequest $request): OrchestratedPaymentResult
    {
        $key = $this->resultJwtVerificationKey($request->captureContextJwt);
        $claims = $this->verifyResultJwtClaims($request->resultJwt, $key, $request->leewaySeconds);

        $this->assertOrchestratedResultMatches($claims, $request);

        return $this->orchestratedPaymentResult($claims);
    }

    /**
     * Requests a Dynamic Currency Conversion rate quote via the currency-conversion
     * endpoint, returning the mapped DccQuote.
     */
    public function requestDccRate(DccRateRequest $request): DccQuote
    {
        return $this->toDccQuote(
            $this->client->post(
                CybersourceEndpoint::CurrencyConversion->path(),
                CurrencyConversionPayload::build($request),
                'request dcc rate',
            ),
            $request->money,
        );
    }

    /**
     * Authorizes (and optionally captures) a payment via the CyberSource payments
     * endpoint, returning the mapped PaymentResult.
     */
    public function charge(ChargeRequest $request): PaymentResult
    {
        return $this->toPaymentResult($this->client->post(
            CybersourceEndpoint::Payments->path(),
            PaymentPayload::build($request),
            'charge',
            $request->idempotencyKey ?? $request->orderReference,
        ));
    }

    /**
     * Captures a previously authorized payment identified by the request's
     * transaction id, returning the mapped PaymentResult.
     */
    public function capture(CaptureRequest $request): PaymentResult
    {
        return $this->toPaymentResult($this->client->post(
            CybersourceEndpoint::Captures->path($request->transactionId),
            CapturePayload::build($request),
            'capture',
            $request->idempotencyKey,
        ));
    }

    /**
     * Refunds a captured payment and maps the response into a RefundResult.
     *
     * The status is resolved from the response with a Refunded success fallback;
     * success, refund id, reason code, and message are derived from that status
     * and the response body.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        $response = $this->client->post(
            CybersourceEndpoint::Refunds->path($request->transactionId),
            RefundPayload::build($request),
            'refund',
            $request->idempotencyKey,
        );

        $status = $this->resolveStatus($response, PaymentStatus::Refunded);

        return new RefundResult(
            success: $status->isSuccessful(),
            status: $status,
            refundId: $this->transactionId($response),
            code: $this->reasonCode($response),
            message: $this->message($response),
            raw: $response,
        );
    }

    /**
     * Voids an uncaptured payment, mapping the response with a Voided success
     * fallback when CyberSource returns no recognized status.
     */
    public function void(VoidRequest $request): PaymentResult
    {
        return $this->toPaymentResult(
            $this->client->post(
                CybersourceEndpoint::Voids->path($request->transactionId),
                VoidPayload::build($request),
                'void',
                $request->idempotencyKey,
            ),
            PaymentStatus::Voided,
        );
    }

    /**
     * Reverses an authorization to release the hold, mapping the response with a
     * Reversed success fallback when CyberSource returns no recognized status.
     */
    public function reverseAuthorization(ReversalRequest $request): PaymentResult
    {
        return $this->toPaymentResult(
            $this->client->post(
                CybersourceEndpoint::Reversals->path($request->transactionId),
                ReversalPayload::build($request),
                'reverse authorization',
                $request->idempotencyKey,
            ),
            PaymentStatus::Reversed,
        );
    }

    /**
     * Starts 3-D Secure by enrolling the payer for authentication (Cardinal setup /
     * device data collection), returning the mapped PayerAuthResult.
     */
    public function enrollPayerAuth(PayerAuthEnrollRequest $request): PayerAuthResult
    {
        return $this->toPayerAuthResult($this->client->post(
            CybersourceEndpoint::Authentications->path(),
            PayerAuthEnrollPayload::build($request),
            'enroll payer auth',
        ));
    }

    /**
     * Completes 3-D Secure by validating the authentication results after the
     * challenge/step-up, returning the mapped PayerAuthResult.
     */
    public function validatePayerAuth(ValidatePayerAuthRequest $request): PayerAuthResult
    {
        return $this->toPayerAuthResult($this->client->post(
            CybersourceEndpoint::AuthenticationResults->path(),
            PayerAuthValidatePayload::build($request),
            'validate payer auth',
        ));
    }

    /**
     * Tokenizes a payment instrument in CyberSource Token Management (TMS).
     *
     * Runs the three-step vaulting flow — create an instrument identifier, create a
     * customer, then attach a payment instrument linking the two — and returns a
     * VaultedInstrument with the resulting ids. Success is determined by the presence
     * of the payment-instrument id; all three raw responses are retained.
     */
    public function vaultInstrument(TokenizeInstrumentRequest $request): VaultedInstrument
    {
        $identifier = $this->client->post(
            CybersourceEndpoint::InstrumentIdentifiers->path(),
            TokenizePayload::instrumentIdentifier($request),
            'create instrument identifier',
        );
        $instrumentIdentifierId = Value::string($identifier['id'] ?? null);

        $customer = $this->client->post(
            CybersourceEndpoint::Customers->path(),
            TokenizePayload::customer($request),
            'create customer',
        );
        $customerId = Value::string($customer['id'] ?? null);

        $instrument = $this->client->post(
            CybersourceEndpoint::CustomerPaymentInstruments->path($customerId),
            TokenizePayload::paymentInstrument($request, $instrumentIdentifierId),
            'create payment instrument',
        );

        return new VaultedInstrument(
            success: filled($instrument['id'] ?? null),
            instrumentIdentifierId: $instrumentIdentifierId,
            customerId: $customerId,
            paymentInstrumentId: isset($instrument['id']) ? Value::string($instrument['id']) : null,
            raw: [
                'instrumentIdentifier' => $identifier,
                'customer' => $customer,
                'paymentInstrument' => $instrument,
            ],
        );
    }

    /**
     * Charges a previously vaulted instrument (merchant-initiated / stored-credential
     * transaction) against the payments endpoint, returning the mapped PaymentResult.
     */
    public function chargeStoredCredential(StoredCredentialChargeRequest $request): PaymentResult
    {
        return $this->toPaymentResult($this->client->post(
            CybersourceEndpoint::Payments->path(),
            StoredCredentialPayload::build($request),
            'charge stored credential',
            $request->idempotencyKey ?? $request->orderReference,
        ));
    }

    /**
     * Charges a digital-wallet token (Apple Pay / Google Pay) by forwarding the encrypted
     * token as fluidData for CyberSource to decrypt, returning the mapped PaymentResult.
     */
    public function chargeWallet(WalletChargeRequest $request): PaymentResult
    {
        return $this->toPaymentResult($this->client->post(
            CybersourceEndpoint::Payments->path(),
            WalletPaymentPayload::build($request),
            'charge wallet',
            $request->idempotencyKey ?? $request->orderReference,
        ));
    }

    /**
     * Fetches a single transaction's details from the TSS endpoint and maps them
     * into a TransactionSnapshot.
     */
    public function getTransaction(string $transactionId): TransactionSnapshot
    {
        return $this->toSnapshot($this->client->get(
            CybersourceEndpoint::TransactionDetails->path($transactionId),
            'get transaction',
        ));
    }

    /**
     * Searches TSS for transactions matching the query and returns the first match
     * as a TransactionSnapshot, or null when the embedded summaries are empty.
     *
     * @param  string  $query  CyberSource transaction search expression.
     */
    public function searchTransaction(string $query): ?TransactionSnapshot
    {
        $response = $this->client->post(
            CybersourceEndpoint::TransactionSearch->path(),
            SearchPayload::build($query),
            'search transaction',
        );

        $summaries = Value::array(data_get($response, '_embedded.transactionSummaries'));

        if (blank($summaries)) {
            return null;
        }

        return $this->toSnapshot(Value::array($summaries[0] ?? null));
    }

    /**
     * Finds the most recent settled transaction carrying a merchant reference, to reconcile before
     * retrying a charge whose response was lost.
     *
     * Searches TSS by `clientReferenceInformation.code` (newest first) and returns the first summary
     * whose status is authorized or captured — so a charge that actually settled but whose response
     * never arrived is adopted rather than repeated. A partial approval (a stranded hold to reverse) and
     * a still-pending transaction are deliberately not adopted. TSS is eventually consistent, so this
     * reconciles a retry spaced minutes-or-more after the lost attempt, not an immediate one.
     *
     * @param  string  $reference  The merchant reference the original charge was sent with.
     */
    public function findSuccessfulTransactionByReference(string $reference): ?TransactionSnapshot
    {
        $response = $this->client->post(
            CybersourceEndpoint::TransactionSearch->path(),
            SearchPayload::build(sprintf('clientReferenceInformation.code:"%s"', $reference), 20),
            'reconcile transaction by reference',
        );

        $summaries = Value::array(data_get($response, '_embedded.transactionSummaries'));

        foreach ($summaries as $summary) {
            $summary = Value::array($summary);

            if ($this->isSettledSummary($summary)) {
                return $this->toSnapshot($summary);
            }
        }

        return null;
    }

    /**
     * Whether a TSS summary represents a settled charge worth adopting on reconcile — an authorized or
     * captured transaction. Excludes partial approvals (a hold to reverse) and pending transactions.
     *
     * @param  array<string, mixed>  $summary  A single TSS transaction summary.
     */
    private function isSettledSummary(array $summary): bool
    {
        return in_array(strtoupper(Value::string($this->statusString($summary))), [
            CybersourceTransactionStatus::Authorized->value,
            CybersourceTransactionStatus::Captured->value,
        ], true);
    }

    /**
     * Lists every transaction matching a TSS query as snapshots, newest first.
     *
     * Returns the whole result set (SearchPayload sorts by id descending), capped at 100 records — a
     * single payment reference never approaches that — so a caller can render a transaction's full
     * history rather than only its first match.
     *
     * @param  string  $query  CyberSource transaction search expression.
     * @return list<TransactionSnapshot>
     */
    public function listTransactions(string $query): array
    {
        $response = $this->client->post(
            CybersourceEndpoint::TransactionSearch->path(),
            SearchPayload::build($query, 100),
            'list transactions',
        );

        $summaries = Value::array(data_get($response, '_embedded.transactionSummaries'));

        return array_values(array_map(
            fn (mixed $summary): TransactionSnapshot => $this->toSnapshot(Value::array($summary)),
            $summaries,
        ));
    }

    /**
     * Lists the full history of a payment by its merchant reference — every authorization, capture,
     * reversal, refund, and retry sent under clientReferenceInformation.code — newest first.
     *
     * @param  string  $reference  The merchant reference the transactions were sent with.
     * @return list<TransactionSnapshot>
     */
    public function listTransactionsByReference(string $reference): array
    {
        return $this->listTransactions(sprintf('clientReferenceInformation.code:"%s"', $reference));
    }

    /**
     * Verifies and parses an inbound CyberSource webhook into a WebhookEvent.
     *
     * Checks the signature against the configured webhook secret, then decodes the
     * body to extract the event type, transaction id, and normalized PaymentStatus
     * (from the nested `payload`/`data` object), returning them alongside the raw
     * payload and the verification result.
     *
     * @param  string  $rawBody  Exact raw request body as received.
     * @param  array<string, string|array<int, string>>  $headers  Inbound request headers.
     */
    public function verifyWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $verified = $this->webhookSignatureIsValid($rawBody, $headers, $this->gatewayCredentials->webhookSecret);

        $decoded = json_decode($rawBody, true);
        $payload = Value::array($decoded);
        $data = Value::array($payload['payload'] ?? $payload['data'] ?? null);

        $statusString = Value::nullableString($data['status'] ?? null);
        $eventType = $payload['eventType'] ?? $payload['type'] ?? null;
        $transactionId = Value::nullableString($data['id'] ?? null);

        return new WebhookEvent(
            verified: $verified,
            eventType: is_string($eventType) ? $eventType : null,
            transactionId: $transactionId,
            status: filled($statusString) ? CybersourceTransactionStatus::toPaymentStatusOrFailed($statusString) : null,
            payload: $payload,
        );
    }

    /**
     * Maps a CyberSource payment JSON response into a PaymentResult DTO.
     *
     * Resolves the normalized PaymentStatus (using the optional operation-specific
     * success fallback), derives the success flag from it, and pulls the transaction
     * id, reason code, and message out of the response.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource response body.
     * @param  PaymentStatus|null  $successStatus  Status to assume on success when CyberSource returns none recognized.
     */
    private function toPaymentResult(array $response, ?PaymentStatus $successStatus = null): PaymentResult
    {
        $status = $this->resolveStatus($response, $successStatus);

        return new PaymentResult(
            success: $status->isSuccessful(),
            status: $status,
            transactionId: $this->transactionId($response),
            code: $this->reasonCode($response),
            message: $this->message($response),
            raw: $response,
        );
    }

    /**
     * Maps a CyberSource currency-conversion JSON response into a DccQuote DTO.
     *
     * Reads the quoted rate id, exchange rate, and timestamp from the currencyConversion
     * block (falling back to amountDetails), and the converted billing amount from
     * orderInformation.amountDetails. DCC is treated as offered when a rate id is present.
     * The original merchant amount is echoed from the request.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource currency-conversion response.
     * @param  Money  $original  The merchant-currency amount that was quoted.
     */
    private function toDccQuote(array $response, Money $original): DccQuote
    {
        $conversion = Value::array(data_get($response, 'currencyConversion') ?? data_get($response, 'orderInformation.amountDetails.currencyConversion'));
        $amountDetails = Value::array(data_get($response, 'orderInformation.amountDetails'));

        $id = Value::nullableString($conversion['id'] ?? null);
        $convertedAmount = Value::nullableString($amountDetails['totalAmount'] ?? null);
        $convertedCurrency = Value::nullableString($amountDetails['currency'] ?? null);

        return new DccQuote(
            offered: filled($id),
            id: $id,
            exchangeRate: Value::nullableString($conversion['exchangeRate'] ?? $amountDetails['exchangeRate'] ?? null),
            originalAmount: $original,
            convertedAmount: ($convertedAmount !== null && $convertedCurrency !== null)
                ? Money::fromDecimalString($convertedAmount, $convertedCurrency)
                : null,
            exchangeRateTimeStamp: Value::nullableString($conversion['exchangeRateTimeStamp'] ?? $amountDetails['exchangeRateTimeStamp'] ?? null),
            raw: $response,
        );
    }

    /**
     * Maps a CyberSource transaction JSON response (from details or search) into a
     * TransactionSnapshot DTO.
     *
     * Pulls the transaction id, normalizes the status via toPaymentStatusOrFailed(),
     * and reads the client reference code as the order reference.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource transaction body or summary.
     */
    private function toSnapshot(array $response): TransactionSnapshot
    {
        return new TransactionSnapshot(
            transactionId: Value::string($response['id'] ?? null),
            status: CybersourceTransactionStatus::toPaymentStatusOrFailed($this->statusString($response)),
            orderReference: Value::nullableString(data_get($response, 'clientReferenceInformation.code')),
            raw: $response,
        );
    }

    /**
     * Maps a CyberSource payer-authentication (3DS) JSON response into a
     * PayerAuthResult DTO.
     *
     * Extracts the consumer-authentication block to surface the step-up URL, access
     * token, and authentication transaction id, and marks success unless the raw
     * status is AUTHENTICATION_FAILED, INVALID_REQUEST, or FAILED.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource authentication response.
     */
    private function toPayerAuthResult(array $response): PayerAuthResult
    {
        $consumerAuth = Value::array($response['consumerAuthenticationInformation'] ?? null);

        $status = Value::string($response['status'] ?? null);

        return new PayerAuthResult(
            success: filled($status) && ! in_array($status, ['AUTHENTICATION_FAILED', 'INVALID_REQUEST', 'FAILED'], true),
            status: $status,
            stepUpUrl: Value::nullableString($consumerAuth['stepUpUrl'] ?? null),
            accessToken: Value::nullableString($consumerAuth['accessToken'] ?? null),
            authenticationTransactionId: Value::nullableString($consumerAuth['authenticationTransactionId'] ?? null),
            consumerAuthenticationInformation: $consumerAuth,
            raw: $response,
        );
    }

    /**
     * Resolves the normalized PaymentStatus for a response.
     *
     * Maps the raw CyberSource status when recognized; otherwise returns the
     * operation-specific success fallback (e.g. Voided/Reversed/Refunded) or
     * Failed when no fallback is supplied.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource response body.
     * @param  PaymentStatus|null  $fallbackSuccess  Status to assume when the raw status is unrecognized.
     */
    private function resolveStatus(array $response, ?PaymentStatus $fallbackSuccess): PaymentStatus
    {
        $mapped = CybersourceTransactionStatus::tryFrom(Value::string($this->statusString($response)))?->toPaymentStatus();

        if ($mapped !== null) {
            return $mapped;
        }

        return $fallbackSuccess ?? PaymentStatus::Failed;
    }

    /**
     * Reads the raw CyberSource status string, falling back from the top-level
     * `status` to the nested `applicationInformation.status` (used by TSS records).
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource response body.
     */
    private function statusString(array $response): ?string
    {
        return Value::nullableString($response['status'] ?? data_get($response, 'applicationInformation.status'));
    }

    /**
     * Extracts the CyberSource transaction id from a response as a string, or null
     * when absent.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource response body.
     */
    private function transactionId(array $response): ?string
    {
        return isset($response['id']) ? Value::string($response['id']) : null;
    }

    /**
     * Extracts a reason/response code from a response, preferring the error reason
     * and falling back to the processor response code, or null when neither is set.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource response body.
     */
    private function reasonCode(array $response): ?string
    {
        $code = data_get($response, 'errorInformation.reason')
            ?? data_get($response, 'processorInformation.responseCode');

        return Value::nullableString($code);
    }

    /**
     * Extracts a human-readable message from a response, preferring the error
     * message and falling back to a top-level `message`, or null when absent or
     * non-string.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource response body.
     */
    private function message(array $response): ?string
    {
        $message = data_get($response, 'errorInformation.message') ?? $response['message'] ?? null;

        return is_string($message) ? $message : null;
    }
}
