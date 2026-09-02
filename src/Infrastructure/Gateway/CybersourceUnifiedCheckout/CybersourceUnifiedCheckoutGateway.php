<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout;

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\BinLookupRequest;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\ConfirmOrchestratedPaymentRequest;
use Hyprpay\Payments\Domain\Command\CreateAccountUpdaterBatchRequest;
use Hyprpay\Payments\Domain\Command\CreatePlanRequest;
use Hyprpay\Payments\Domain\Command\CreateReportRequest;
use Hyprpay\Payments\Domain\Command\CreateReportSubscriptionRequest;
use Hyprpay\Payments\Domain\Command\CreateSubscriptionRequest;
use Hyprpay\Payments\Domain\Command\CreateWebhookRequest;
use Hyprpay\Payments\Domain\Command\DccRateRequest;
use Hyprpay\Payments\Domain\Command\DownloadReportRequest;
use Hyprpay\Payments\Domain\Command\ListReportsRequest;
use Hyprpay\Payments\Domain\Command\ListSubscriptionsRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\PayerAuthSetupRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Command\UpdatePaymentInstrumentRequest;
use Hyprpay\Payments\Domain\Command\UpdatePlanRequest;
use Hyprpay\Payments\Domain\Command\UpdateSubscriptionRequest;
use Hyprpay\Payments\Domain\Command\UpdateWebhookRequest;
use Hyprpay\Payments\Domain\Command\ValidateBankAccountRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Command\WalletChargeRequest;
use Hyprpay\Payments\Domain\Contract\EventDispatcher;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Enum\AccountUpdaterBatchStatus;
use Hyprpay\Payments\Domain\Enum\BillingPeriodUnit;
use Hyprpay\Payments\Domain\Enum\BinLookupStatus;
use Hyprpay\Payments\Domain\Enum\CardFundingSource;
use Hyprpay\Payments\Domain\Enum\CardPlatform;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentInstrumentState;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Enum\PlanStatus;
use Hyprpay\Payments\Domain\Enum\ReportDefinitionName;
use Hyprpay\Payments\Domain\Enum\ReportFormat;
use Hyprpay\Payments\Domain\Enum\ReportFrequency;
use Hyprpay\Payments\Domain\Enum\ReportStatus;
use Hyprpay\Payments\Domain\Enum\ReportSubscriptionType;
use Hyprpay\Payments\Domain\Enum\SubscriptionStatus;
use Hyprpay\Payments\Domain\Enum\WebhookSecurityType;
use Hyprpay\Payments\Domain\Enum\WebhookStatus;
use Hyprpay\Payments\Domain\Event\PayerAuthenticationEciRejected;
use Hyprpay\Payments\Domain\Result\AccountUpdaterBatch;
use Hyprpay\Payments\Domain\Result\BankAccountValidationResult;
use Hyprpay\Payments\Domain\Result\BinLookupResult;
use Hyprpay\Payments\Domain\Result\CheckoutSession;
use Hyprpay\Payments\Domain\Result\DccQuote;
use Hyprpay\Payments\Domain\Result\OrchestratedPaymentResult;
use Hyprpay\Payments\Domain\Result\PayerAuthResult;
use Hyprpay\Payments\Domain\Result\PayerAuthSetupResult;
use Hyprpay\Payments\Domain\Result\PaymentInstrument;
use Hyprpay\Payments\Domain\Result\PaymentInstrumentPage;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\PlanResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\Report;
use Hyprpay\Payments\Domain\Result\ReportDefinition;
use Hyprpay\Payments\Domain\Result\ReportDefinitionField;
use Hyprpay\Payments\Domain\Result\ReportFile;
use Hyprpay\Payments\Domain\Result\ReportSubscription;
use Hyprpay\Payments\Domain\Result\SubscriptionPage;
use Hyprpay\Payments\Domain\Result\SubscriptionResult;
use Hyprpay\Payments\Domain\Result\TransactionSnapshot;
use Hyprpay\Payments\Domain\Result\VaultedInstrument;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\Result\WebhookSecurityKey;
use Hyprpay\Payments\Domain\Result\WebhookSubscription;
use Hyprpay\Payments\Domain\ValueObject\BillingPeriod;
use Hyprpay\Payments\Domain\ValueObject\CybersourceEci;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Domain\ValueObject\WebhookProduct;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns\ParsesTransientToken;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns\VerifiesCybersourceWebhook;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns\VerifiesResultJwt;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums\CybersourceEndpoint;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums\CybersourceSubscriptionStatus;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums\CybersourceTransactionStatus;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\AccountUpdaterPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\AccountValidationPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\BinLookupPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\CaptureContextPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\CapturePayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\CurrencyConversionPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\MicroformCaptureContextPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PayerAuthEnrollPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PayerAuthSetupPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PayerAuthValidatePayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PaymentInstrumentPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PaymentPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\PlanPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\RefundPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\ReportPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\ReversalPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\SearchPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\StoredCredentialPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\SubscriptionPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\TokenizePayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\VoidPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\WalletPaymentPayload;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\WebhookPayload;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * CyberSource Unified Checkout payment gateway adapter.
 *
 * The SDK's first concrete gateway: it implements every operation CyberSource
 * supports (capture-context creation, charge, capture, refund, void, authorization
 * reversal, 3DS payer-auth enroll/validate, instrument vaulting, stored-credential
 * charges, recurring subscriptions, transaction lookup/search, and webhook
 * verification). Requests are built by the Payloads/* helpers, sent through
 * CybersourceClient, and the JSON responses are mapped back into SDK result DTOs by
 * the private mapper methods. Transient-token decoding and webhook signature checks
 * come from the ParsesTransientToken and VerifiesCybersourceWebhook concerns.
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
     *
     * An optional {@see EventDispatcher} lets the driver emit domain events for outcomes the
     * decorator cannot model — currently a {@see PayerAuthenticationEciRejected} when a 3-D
     * Secure result is rejected for a non-authenticated ECI. It is nullable so the driver can
     * still be constructed directly (bypassing the factory) without event wiring.
     */
    public function __construct(
        GatewayCredentials $credentials,
        HttpClient $http,
        private readonly ?EventDispatcher $events = null,
    ) {
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
     * JWT plus those front-end bootstrap values. The endpoint is chosen by the
     * `capture_context_endpoint` credential (see {@see captureContextEndpoint()}).
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        return $this->captureContextSession($this->client->postForBody(
            $this->captureContextEndpoint()->path(),
            CaptureContextPayload::build($request, $this->gatewayCredentials),
            'create checkout session',
        ));
    }

    /**
     * Creates a Flex Microform capture context (server-to-server) and returns it as a CheckoutSession.
     *
     * Microform is CyberSource's low-level, PCI-friendly card-field tokenizer — distinct from the full
     * Unified Checkout widget {@see createCheckoutSession()} drives. This posts a Microform capture-context
     * request to `/microform/v2/sessions` and returns the signed JWT plus the embedded Microform
     * client-library URL and integrity hash. Load Microform.js with the JWT to render the secure card
     * fields; the browser mints a transient token that {@see charge()} (or {@see enrollPayerAuth()} /
     * {@see vaultInstrument()}) then processes server-side — the same transient-token path Unified Checkout
     * uses. Unlike a checkout session the Microform context carries no order amount or capture mandate; the
     * amount is applied when the transient token is charged. This is a CyberSource-specific operation,
     * outside the shared {@see PaymentGatewayInterface}.
     */
    public function createMicroformSession(CheckoutSessionRequest $request): CheckoutSession
    {
        return $this->captureContextSession($this->client->postForBody(
            CybersourceEndpoint::MicroformSessions->path(),
            MicroformCaptureContextPayload::build($request),
            'create microform session',
        ));
    }

    /**
     * Decodes a CyberSource capture-context JWT (Unified Checkout or Microform) into a CheckoutSession.
     *
     * Both products return a bare, signed JWT whose claims embed the front-end client-library URL and
     * subresource-integrity hash under `ctx[0].data`; this reads those bootstrap values and returns the
     * JWT alongside them for the browser integration.
     */
    private function captureContextSession(string $jwt): CheckoutSession
    {
        $jwt = trim($jwt);
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
     * The capture-context endpoint for this merchant.
     *
     * Defaults to `/up/v1/capture-contexts`; set the `capture_context_endpoint` credential to
     * `sessions` to target the `/uc/v1/sessions` Unified Checkout Sessions API instead. The two
     * share the capture-context schema this driver builds, so the choice is purely which URL the
     * merchant's account is provisioned for.
     */
    private function captureContextEndpoint(): CybersourceEndpoint
    {
        $endpoint = $this->gatewayCredentials->extra['capture_context_endpoint'] ?? 'capture-contexts';

        return $endpoint === 'sessions'
            ? CybersourceEndpoint::Sessions
            : CybersourceEndpoint::CaptureContexts;
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
     * Sets up 3-D Secure by priming device data collection for the payer, returning the
     * mapped PayerAuthSetupResult with the access token and device-data-collection URL.
     *
     * Reads the transient token's `jti` claim to reference the card without re-sending the
     * whole token, falling back to the full transient token when the claim cannot be read.
     */
    public function setupPayerAuth(PayerAuthSetupRequest $request): PayerAuthSetupResult
    {
        return $this->toPayerAuthSetupResult($this->client->post(
            CybersourceEndpoint::AuthenticationSetups->path(),
            PayerAuthSetupPayload::build($request, $this->transientTokenJti($request->transientToken)),
            'setup payer auth',
        ));
    }

    /**
     * Starts 3-D Secure by enrolling the payer for authentication, returning the mapped
     * PayerAuthResult with any step-up challenge details.
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
        $jti = filled($request->transientToken)
            ? $this->transientTokenJti($request->transientToken)
            : null;

        return $this->toPayerAuthResult($this->client->post(
            CybersourceEndpoint::AuthenticationResults->path(),
            PayerAuthValidatePayload::build($request, $jti),
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
     * Reads a vaulted payment instrument back from the vault (TMS).
     *
     * Returns the stored record — expiry, masked number, issuer standing, default flag — rather
     * than the ids {@see vaultInstrument()} produced. Reading the state at rest is how a closed
     * or expired card is caught before a scheduled rebill declines on it. This is a
     * CyberSource-specific operation, outside the shared {@see PaymentGatewayInterface}.
     *
     * @param  string  $customerId  Vault customer the instrument is stored under.
     * @param  string  $paymentInstrumentId  Vault payment-instrument identifier.
     */
    public function getPaymentInstrument(string $customerId, string $paymentInstrumentId): PaymentInstrument
    {
        return $this->toPaymentInstrument($this->client->get(
            CybersourceEndpoint::CustomerPaymentInstrument->path($customerId, $paymentInstrumentId),
            'get payment instrument',
        ), $customerId);
    }

    /**
     * Lists a customer's vaulted payment instruments, one page at a time.
     *
     * CyberSource pages this at 20 records by default and caps it at 100, so read
     * {@see PaymentInstrumentPage::hasMore()} rather than assuming one call returns every card
     * a customer holds. {@see PaymentInstrumentPage::default()} picks out the instrument
     * payments fall back to.
     *
     * @param  string  $customerId  Vault customer whose instruments are listed.
     * @param  int  $limit  Page size; CyberSource caps it at 100.
     * @param  int  $offset  Records to skip, for paging.
     */
    public function listPaymentInstruments(string $customerId, int $limit = 20, int $offset = 0): PaymentInstrumentPage
    {
        $response = $this->client->get(
            CybersourceEndpoint::CustomerPaymentInstruments->path($customerId)
                .'?'.http_build_query(['offset' => $offset, 'limit' => $limit]),
            'list payment instruments',
        );

        $instruments = Value::array(data_get($response, '_embedded.paymentInstruments'));

        return new PaymentInstrumentPage(
            instruments: array_values(array_map(
                fn (mixed $instrument): PaymentInstrument => $this->toPaymentInstrument(Value::array($instrument), $customerId),
                $instruments,
            )),
            totalCount: Value::int($response['total'] ?? null),
            offset: Value::int($response['offset'] ?? $offset),
            limit: Value::int($response['limit'] ?? $limit),
            raw: $response,
        );
    }

    /**
     * Amends a vaulted payment instrument in place (TMS).
     *
     * A partial update, and the practical fix for a reissued card: re-dating the stored expiry
     * keeps every subscription and stored-credential charge already pointing at this instrument
     * working, with no re-collection. The card number itself cannot be changed — it belongs to
     * the instrument identifier behind the instrument — so a genuinely new card is vaulted
     * afresh. Setting `makeDefault` moves the customer's default here, which is also the
     * prerequisite for deleting whichever instrument is currently the default.
     */
    public function updatePaymentInstrument(UpdatePaymentInstrumentRequest $request): PaymentInstrument
    {
        return $this->toPaymentInstrument($this->client->patch(
            CybersourceEndpoint::CustomerPaymentInstrument->path($request->customerId, $request->paymentInstrumentId),
            PaymentInstrumentPayload::update($request),
            'update payment instrument',
        ), $request->customerId);
    }

    /**
     * Deletes a vaulted payment instrument, so the stored credential can no longer be charged.
     *
     * CyberSource also deletes the instrument identifier behind it when no other payment
     * instrument references that card. A customer's *default* instrument cannot be deleted while
     * they hold others — promote another with
     * {@see UpdatePaymentInstrumentRequest::$makeDefault} first.
     *
     * @param  string  $customerId  Vault customer the instrument is stored under.
     * @param  string  $paymentInstrumentId  Vault payment-instrument identifier to delete.
     * @return bool True once CyberSource has accepted the deletion.
     */
    public function deletePaymentInstrument(string $customerId, string $paymentInstrumentId): bool
    {
        $this->client->delete(
            CybersourceEndpoint::CustomerPaymentInstrument->path($customerId, $paymentInstrumentId),
            'delete payment instrument',
        );

        return true;
    }

    /**
     * Reads a vault customer record (TMS), including which instrument is their default.
     *
     * @param  string  $customerId  Vault customer identifier.
     * @return array<string, mixed> The raw customer record; its shape is merchant-configurable.
     */
    public function getCustomer(string $customerId): array
    {
        return $this->client->get(
            CybersourceEndpoint::Customer->path($customerId),
            'get customer',
        );
    }

    /**
     * Deletes a vault customer and every payment instrument stored under them.
     *
     * The blunt instrument for an erasure request: it removes the customer's stored credentials
     * outright, so any subscription still billing that customer will fail afterwards — cancel
     * those first.
     *
     * @param  string  $customerId  Vault customer identifier to delete.
     * @return bool True once CyberSource has accepted the deletion.
     */
    public function deleteCustomer(string $customerId): bool
    {
        $this->client->delete(
            CybersourceEndpoint::Customer->path($customerId),
            'delete customer',
        );

        return true;
    }

    /**
     * Deletes an instrument identifier — the vault record standing for the card number itself.
     *
     * Deleting the identifier removes the underlying card for every payment instrument built on
     * it, so prefer {@see deletePaymentInstrument()} unless the card is genuinely being purged.
     *
     * @param  string  $instrumentIdentifierId  Vault instrument-identifier to delete.
     * @return bool True once CyberSource has accepted the deletion.
     */
    public function deleteInstrumentIdentifier(string $instrumentIdentifierId): bool
    {
        $this->client->delete(
            CybersourceEndpoint::InstrumentIdentifier->path($instrumentIdentifierId),
            'delete instrument identifier',
        );

        return true;
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
     * Creates a recurring billing plan — the reusable template subscriptions are created from.
     *
     * A plan carries the cadence, cycle count, and price, so subscriptions can reference shared
     * pricing through {@see CreateSubscriptionRequest::$planId} instead of restating it (and can
     * still override the amount for per-customer pricing). Create it as
     * {@see PlanStatus::Draft} to stage it; only an active plan can back a new subscription.
     * This is a CyberSource-specific operation, outside the shared {@see PaymentGatewayInterface}.
     */
    public function createPlan(CreatePlanRequest $request): PlanResult
    {
        return $this->toPlanResult($this->client->post(
            CybersourceEndpoint::Plans->path(),
            PlanPayload::build($request),
            'create plan',
        ));
    }

    /**
     * Fetches a plan's current state — status, cadence, cycle count, and pricing — by its id.
     *
     * @param  string  $planId  CyberSource plan id.
     */
    public function getPlan(string $planId): PlanResult
    {
        return $this->toPlanResult($this->client->get(
            CybersourceEndpoint::Plan->path($planId),
            'get plan',
        ));
    }

    /**
     * Lists the merchant's recurring billing plans.
     *
     * @return list<PlanResult>
     */
    public function listPlans(): array
    {
        $response = $this->client->get(CybersourceEndpoint::Plans->path(), 'list plans');

        $plans = Value::array($response['plans'] ?? null);

        return array_values(array_map(
            fn (mixed $plan): PlanResult => $this->toPlanResult(Value::array($plan)),
            $plans,
        ));
    }

    /**
     * Amends an existing plan in place.
     *
     * A partial update. Unlike a subscription — whose cadence is fixed once it exists — a plan's
     * billing period can be changed here, because a plan is a template rather than a live
     * billing agreement. The change governs subscriptions created from the plan afterwards; it
     * does not retroactively re-price those already running on it.
     */
    public function updatePlan(UpdatePlanRequest $request): PlanResult
    {
        return $this->toPlanResult($this->client->patch(
            CybersourceEndpoint::Plan->path($request->planId),
            PlanPayload::update($request),
            'update plan',
        ));
    }

    /**
     * Activates a plan so new subscriptions can be created against it.
     *
     * @param  string  $planId  CyberSource plan id to activate.
     */
    public function activatePlan(string $planId): PlanResult
    {
        return $this->toPlanResult($this->client->postWithoutBody(
            CybersourceEndpoint::PlanActivate->path($planId),
            'activate plan',
        ));
    }

    /**
     * Withdraws a plan so no further subscriptions can be created against it.
     *
     * Subscriptions already running on the plan keep billing — deactivating closes the plan to
     * new sign-ups, it does not cancel existing agreements.
     *
     * @param  string  $planId  CyberSource plan id to deactivate.
     */
    public function deactivatePlan(string $planId): PlanResult
    {
        return $this->toPlanResult($this->client->postWithoutBody(
            CybersourceEndpoint::PlanDeactivate->path($planId),
            'deactivate plan',
        ));
    }

    /**
     * Deletes a plan outright.
     *
     * Only a plan nothing depends on can be removed; withdraw a plan that has subscriptions with
     * {@see deactivatePlan()} instead.
     *
     * @param  string  $planId  CyberSource plan id to delete.
     * @return bool True once CyberSource has accepted the deletion.
     */
    public function deletePlan(string $planId): bool
    {
        $this->client->delete(CybersourceEndpoint::Plan->path($planId), 'delete plan');

        return true;
    }

    /**
     * Asks CyberSource for an unused plan code, for merchants that want the gateway to allocate
     * the code rather than assigning their own.
     *
     * @return string|null The generated plan code, or null when the response carried none.
     */
    public function generatePlanCode(): ?string
    {
        $response = $this->client->get(CybersourceEndpoint::PlanCode->path(), 'generate plan code');

        return Value::nullableString(data_get($response, 'planInformation.code') ?? $response['code'] ?? null);
    }

    /**
     * Lists the payments a subscription has raised — settled, scheduled, and failed.
     *
     * This is how a `DELINQUENT` subscription is diagnosed without waiting for a webhook: read
     * the failed rebill from here and hand its raw response to
     * {@see DeclineClassifier::classify()} to tell a permanent refusal from a transient one.
     *
     * @param  string  $subscriptionId  CyberSource subscription id.
     * @return array<string, mixed> The raw payments response; its shape varies by subscription type.
     */
    public function listSubscriptionPayments(string $subscriptionId): array
    {
        return $this->client->get(
            CybersourceEndpoint::SubscriptionPayments->path($subscriptionId),
            'list subscription payments',
        );
    }

    /**
     * Opens a recurring subscription that CyberSource bills on its own schedule (Recurring Billing).
     *
     * Posts to `/rbs/v1/subscriptions` to enrol a vaulted TMS customer on a billing series: unlike
     * {@see chargeStoredCredential()}, which bills a saved instrument once per call, this hands the
     * schedule to CyberSource, which raises each cycle's charge itself. The cadence comes from a
     * referenced plan, from the inline billingPeriod/billingCycles on the request, or from both with
     * the inline values overriding the plan. Nothing is charged here — the first charge falls on the
     * request's start date. This is a CyberSource-specific operation, outside the shared
     * {@see PaymentGatewayInterface}.
     */
    public function createSubscription(CreateSubscriptionRequest $request): SubscriptionResult
    {
        return $this->toSubscriptionResult($this->client->post(
            CybersourceEndpoint::Subscriptions->path(),
            SubscriptionPayload::build($request),
            'create subscription',
            $request->idempotencyKey ?? $request->orderReference,
        ));
    }

    /**
     * Amends an existing subscription in place (`PATCH /rbs/v1/subscriptions/{id}`).
     *
     * A partial update: only the fields set on the request are sent, so everything else keeps its
     * current value. What can change is narrower than what a create sets — CyberSource's update
     * schema takes a new cycle count but no billing period, and new amounts but no currency, so
     * re-pricing works in the subscription's existing currency while changing the cadence means
     * cancelling and re-creating. Watch {@see SubscriptionResult::$requestStatus}: an update can
     * come back PENDING_REVIEW, meaning it was accepted but is held for review rather than applied
     * yet. This is a CyberSource-specific operation, outside the shared
     * {@see PaymentGatewayInterface}.
     */
    public function updateSubscription(UpdateSubscriptionRequest $request): SubscriptionResult
    {
        return $this->toSubscriptionResult($this->client->patch(
            CybersourceEndpoint::Subscription->path($request->subscriptionId),
            SubscriptionPayload::buildUpdate($request),
            'update subscription',
            $request->idempotencyKey ?? $request->orderReference,
        ));
    }

    /**
     * Fetches a subscription's current state — status, plan, cadence, and amounts — by its id.
     *
     * @param  string  $subscriptionId  CyberSource subscription id returned when the subscription was created.
     */
    public function getSubscription(string $subscriptionId): SubscriptionResult
    {
        return $this->toSubscriptionResult($this->client->get(
            CybersourceEndpoint::Subscription->path($subscriptionId),
            'get subscription',
        ));
    }

    /**
     * Lists subscriptions matching a filter, one page at a time (CyberSource's getAllSubscriptions).
     *
     * Every filter on the request is optional and they combine; passing none walks the whole book.
     * The result set is paged — CyberSource caps a page at 100 records — so read
     * {@see SubscriptionPage::hasMore()} and advance with
     * {@see ListSubscriptionsRequest::nextPage()} rather than assuming one call returns everything.
     * Filtering by {@see SubscriptionStatus::Delinquent} is the practical way to find the
     * subscriptions whose last rebill failed and need dunning.
     */
    public function listSubscriptions(ListSubscriptionsRequest $request): SubscriptionPage
    {
        return $this->toSubscriptionPage($this->client->get(
            CybersourceEndpoint::Subscriptions->path().SubscriptionPayload::query($request),
            'list subscriptions',
        ), $request);
    }

    /**
     * Cancels a subscription permanently, stopping every future charge.
     *
     * Terminal: a cancelled subscription cannot be reactivated, so use
     * {@see suspendSubscription()} to pause a series you intend to resume. Already-settled
     * charges are untouched — refund them separately with {@see refund()}.
     *
     * @param  string  $subscriptionId  CyberSource subscription id to cancel.
     */
    public function cancelSubscription(string $subscriptionId): SubscriptionResult
    {
        return $this->toSubscriptionResult($this->client->postWithoutBody(
            CybersourceEndpoint::SubscriptionCancel->path($subscriptionId),
            'cancel subscription',
        ));
    }

    /**
     * Suspends a subscription, pausing its charges without ending it.
     *
     * Reversible, unlike {@see cancelSubscription()}: {@see activateSubscription()} resumes the
     * series from the next billing cycle.
     *
     * @param  string  $subscriptionId  CyberSource subscription id to suspend.
     */
    public function suspendSubscription(string $subscriptionId): SubscriptionResult
    {
        return $this->toSubscriptionResult($this->client->postWithoutBody(
            CybersourceEndpoint::SubscriptionSuspend->path($subscriptionId),
            'suspend subscription',
        ));
    }

    /**
     * Reactivates a suspended subscription from its next billing cycle.
     *
     * Only a suspended subscription can be reactivated — a cancelled or completed one cannot.
     * `$processMissedPayments` decides whether the cycles missed while suspended are billed on
     * reactivation; CyberSource honours it only when the merchant's reactivation setting is
     * "Ask each time before reactivating" and silently ignores it under every other setting.
     * Read `reactivationInformation` from {@see getSubscription()} first to see how many payments
     * were missed and what they total.
     *
     * @param  string  $subscriptionId  CyberSource subscription id to reactivate.
     * @param  bool  $processMissedPayments  Whether to bill the cycles missed during suspension.
     */
    public function activateSubscription(string $subscriptionId, bool $processMissedPayments = true): SubscriptionResult
    {
        return $this->toSubscriptionResult($this->client->postWithoutBody(
            sprintf(
                '%s?processMissedPayments=%s',
                CybersourceEndpoint::SubscriptionActivate->path($subscriptionId),
                $processMissedPayments ? 'true' : 'false',
            ),
            'activate subscription',
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
     * Submits vaulted cards to Account Updater so the networks can refresh them.
     *
     * Asks Visa, Mastercard, or Amex whether the cards behind the given TMS tokens have been
     * reissued, re-dated, or closed, and pushes the answers back into the vault. This is the
     * standing fix for recurring-billing churn: without it a reissued card fails every scheduled
     * charge permanently, and you only learn of it from the decline. Only token ids are sent —
     * no card number leaves the vault.
     *
     * Processing is asynchronous and takes hours to days, so this returns a batch id to poll with
     * {@see getAccountUpdaterBatchStatus()}; read the per-card changes with
     * {@see getAccountUpdaterBatchReport()} once complete. Amex cards must be submitted as an
     * {@see AccountUpdaterBatchType::AmexRegistration} batch. Needs the Account Updater
     * entitlement. This is a CyberSource-specific operation, outside the shared
     * {@see PaymentGatewayInterface}.
     */
    public function createAccountUpdaterBatch(CreateAccountUpdaterBatchRequest $request): AccountUpdaterBatch
    {
        return $this->toAccountUpdaterBatch($this->client->post(
            CybersourceEndpoint::AccountUpdaterBatches->path(),
            AccountUpdaterPayload::build($request),
            'create account updater batch',
        ));
    }

    /**
     * Polls an Account Updater batch for its progress and totals.
     *
     * {@see AccountUpdaterBatch::isComplete()} says whether the report is ready;
     * {@see AccountUpdaterBatch::hasUpdates()} whether the networks changed anything worth
     * reading it for.
     *
     * @param  string  $batchId  Batch id returned when the batch was submitted.
     */
    public function getAccountUpdaterBatchStatus(string $batchId): AccountUpdaterBatch
    {
        return $this->toAccountUpdaterBatch($this->client->get(
            CybersourceEndpoint::AccountUpdaterBatchStatus->path($batchId),
            'get account updater batch status',
        ));
    }

    /**
     * Fetches the per-card report for a completed Account Updater batch.
     *
     * Lists each submitted token against what the networks said — response code and reason, the
     * new masked number, expiry, and card type where one changed — so the refreshed vault records
     * can be reconciled against your own copies, and closed accounts retired rather than retried.
     *
     * @param  string  $batchId  Batch id to report on.
     * @return array<string, mixed> The raw batch report; its record shape varies by network response.
     */
    public function getAccountUpdaterBatchReport(string $batchId): array
    {
        return $this->client->get(
            CybersourceEndpoint::AccountUpdaterBatchReport->path($batchId),
            'get account updater batch report',
        );
    }

    /**
     * Lists Account Updater batches, most recent first as CyberSource returns them.
     *
     * @param  int  $limit  Page size.
     * @param  int  $offset  Records to skip, for paging.
     * @param  string|null  $fromDate  Optional lower bound, ISO 8601 basic format (`yyyyMMddTHHmmssZ`).
     * @param  string|null  $toDate  Optional upper bound, same format.
     * @return list<AccountUpdaterBatch>
     */
    public function listAccountUpdaterBatches(int $limit = 20, int $offset = 0, ?string $fromDate = null, ?string $toDate = null): array
    {
        $query = array_filter([
            'offset' => (string) $offset,
            'limit' => (string) $limit,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ], filled(...));

        $response = $this->client->get(
            CybersourceEndpoint::AccountUpdaterBatches->path().'?'.http_build_query($query),
            'list account updater batches',
        );

        $batches = Value::array(data_get($response, '_embedded.batches'));

        return array_values(array_map(
            fn (mixed $batch): AccountUpdaterBatch => $this->toAccountUpdaterBatch(Value::array($batch)),
            $batches,
        ));
    }

    /**
     * Looks up what a card actually is, before charging it (BIN Lookup).
     *
     * Asks the networks for the credential's brand, funding source, issuer country, platform, and
     * capabilities, so the decisions that must be made *before* an authorization are made on fact:
     * how to route, whether to surcharge, whether to offer installments, whether 3-D Secure is even
     * supported. Accepts a raw PAN or — preferably — a transient token or vault reference, in which
     * case no card number leaves the vault.
     *
     * Read {@see BinLookupResult::isResolved()} before trusting the attributes: a lookup that
     * matched multiple ranges or none leaves them untrustworthy, and "unknown" is never grounds for
     * refusing a payment on its own. This is a CyberSource-specific operation, outside the shared
     * {@see PaymentGatewayInterface}, so a raw PAN passed here is never seen by the logging decorator.
     */
    public function lookupBin(BinLookupRequest $request): BinLookupResult
    {
        $response = $this->client->post(
            CybersourceEndpoint::BinLookups->path(),
            BinLookupPayload::build($request),
            'bin lookup',
        );

        $card = Value::array(data_get($response, 'paymentAccountInformation.card'));
        $features = Value::array(data_get($response, 'paymentAccountInformation.features'));
        $issuer = Value::array($response['issuerInformation'] ?? null);

        return new BinLookupResult(
            status: BinLookupStatus::tryFrom(strtoupper(Value::string($response['status'] ?? null))),
            cardType: Value::nullableString($card['type'] ?? null),
            brandName: Value::nullableString($card['brandName'] ?? null),
            currency: Value::nullableString($card['currency'] ?? null),
            maxLength: isset($card['maxLength']) ? Value::int($card['maxLength']) : null,
            credentialType: Value::nullableString($card['credentialType'] ?? null),
            fundingSource: CardFundingSource::tryFrom(strtoupper(Value::string($features['accountFundingSource'] ?? null))),
            fundingSubType: Value::nullableString($features['accountFundingSourceSubType'] ?? null),
            platform: CardPlatform::tryFrom(strtoupper(Value::string($features['cardPlatform'] ?? null))),
            cardProduct: Value::nullableString($features['cardProduct'] ?? null),
            issuerName: Value::nullableString($issuer['name'] ?? null),
            issuerCountry: Value::nullableString($issuer['country'] ?? null),
            accountPrefix: Value::nullableString($issuer['accountPrefix'] ?? null),
            issuerPhone: Value::nullableString($issuer['phoneNumber'] ?? null),
            features: $features,
            raw: $response,
        );
    }

    /**
     * Validates a bank account with the Visa Bank Account Validation Service (BAVS).
     *
     * Checks that a routing-number / account-number pair is a real, open account *before* an ACH
     * debit is attempted — how Nacha's account-validation mandate for WEB debits is satisfied.
     * Nothing is charged, held, or authorised: this is a standalone check, not a payment. Pass
     * either the raw bank details or a vaulted customer/instrument token, which keeps the raw
     * numbers in the vault. Read the result with {@see BankAccountValidationResult::isValid()},
     * and treat {@see BankAccountValidationResult::isInconclusive()} as "retry", never as a bad
     * account. Needs the BAVS entitlement on the account. This is a CyberSource-specific
     * operation, outside the shared {@see PaymentGatewayInterface}.
     */
    public function validateBankAccount(ValidateBankAccountRequest $request): BankAccountValidationResult
    {
        $response = $this->client->post(
            CybersourceEndpoint::AccountValidations->path(),
            AccountValidationPayload::build($request),
            'validate bank account',
        );

        $validation = Value::array($response['bankAccountValidation'] ?? null);

        return new BankAccountValidationResult(
            resultCode: isset($validation['resultCode']) ? Value::int($validation['resultCode'], -999) : null,
            rawValidationCode: isset($validation['rawValidationCode']) ? Value::int($validation['rawValidationCode'], -999) : null,
            resultMessage: Value::nullableString($validation['resultMessage'] ?? null),
            requestId: Value::nullableString($response['requestId'] ?? null),
            submitTimeUtc: Value::nullableString($response['submitTimeUtc'] ?? null),
            orderReference: Value::nullableString(data_get($response, 'clientReferenceInformation.code')),
            raw: $response,
        );
    }

    /**
     * Lists the report definitions this merchant may run — the catalogue behind
     * {@see CreateReportRequest::$definitionName}.
     *
     * Which definitions exist depends on the merchant's entitlements and subscription family, so
     * the catalogue is discovered here rather than fixed in the SDK: hard-coding the names would
     * reject reports a given merchant is legitimately entitled to. The listing carries no field
     * lists — use {@see getReportDefinition()} for those.
     *
     * @param  ReportSubscriptionType|null  $subscriptionType  Family to list; CyberSource defaults to Custom.
     * @param  string|null  $organizationId  Organization to list for; defaults to the credentials' organization.
     * @return list<ReportDefinition>
     */
    public function listReportDefinitions(?ReportSubscriptionType $subscriptionType = null, ?string $organizationId = null): array
    {
        $response = $this->client->get(
            CybersourceEndpoint::ReportDefinitions->path()
                .ReportPayload::definitionQuery($subscriptionType, null, $this->organizationId($organizationId)),
            'list report definitions',
        );

        $definitions = Value::array($response['reportDefinitions'] ?? null);

        return array_values(array_map(
            fn (mixed $definition): ReportDefinition => $this->toReportDefinition(Value::array($definition)),
            $definitions,
        ));
    }

    /**
     * Fetches one report definition, including the fields it offers.
     *
     * The field list is a property of the definition — a transaction report's columns are not a
     * chargeback report's — so this is how a valid `reportFields` list is assembled:
     * {@see ReportDefinition::fieldNames()} for everything on offer,
     * {@see ReportDefinition::requiredFieldNames()} for the columns the report always carries.
     * The field list also varies by format, so the format asked for here should be the one the
     * report will be generated in.
     *
     * @param  ReportDefinitionName|string  $definitionName  Definition to describe — an enum case, or a raw name from {@see listReportDefinitions()}.
     * @param  ReportSubscriptionType|null  $subscriptionType  Family to resolve the name under; CyberSource defaults to Custom.
     * @param  ReportFormat|null  $format  Format whose field list is wanted; CyberSource defaults to CSV.
     * @param  string|null  $organizationId  Organization the definition belongs to.
     */
    public function getReportDefinition(
        ReportDefinitionName|string $definitionName,
        ?ReportSubscriptionType $subscriptionType = null,
        ?ReportFormat $format = null,
        ?string $organizationId = null,
    ): ReportDefinition {
        return $this->toReportDefinition($this->client->get(
            CybersourceEndpoint::ReportDefinition->path(rawurlencode(ReportDefinitionName::toValue($definitionName)))
                .ReportPayload::definitionQuery($subscriptionType, $format, $this->organizationId($organizationId)),
            'get report definition',
        ));
    }

    /**
     * Queues a one-off (ad-hoc) report covering a fixed window.
     *
     * Generation is asynchronous and CyberSource answers with an empty 201, so there is nothing
     * to return but acceptance: find the queued report with {@see listReports()} (searching the
     * window by name) and download it once its status reports ready. This is a CyberSource-specific
     * operation, outside the shared {@see PaymentGatewayInterface}.
     *
     * @return bool True once CyberSource has accepted the report for generation.
     */
    public function createReport(CreateReportRequest $request): bool
    {
        $this->client->post(
            CybersourceEndpoint::Reports->path(),
            ReportPayload::adhoc($request, $this->organizationId($request->organizationId)),
            'create report',
        );

        return true;
    }

    /**
     * Lists the reports available over a window, newest first as CyberSource returns them.
     *
     * The window is required and applies either to when each report ran or to the period it
     * covers, per the request's `timeQueryType`. This is how a report created by
     * {@see createReport()} is located: search its window by name, then read the id and status
     * off the match.
     *
     * @return list<Report>
     */
    public function listReports(ListReportsRequest $request): array
    {
        $response = $this->client->get(
            CybersourceEndpoint::Reports->path().ReportPayload::searchQuery($request, $this->organizationId($request->organizationId)),
            'list reports',
        );

        $results = is_array($response['reportSearchResults'] ?? null) ? $response['reportSearchResults'] : [];

        return array_values(array_map(
            fn (mixed $report): Report => $this->toReport(Value::array($report)),
            $results,
        ));
    }

    /**
     * Fetches one report's record — status, window, format, and definition — by its report id.
     *
     * This returns the report's metadata, not its contents; the file itself comes from
     * {@see downloadReport()}.
     *
     * @param  string  $reportId  CyberSource report id, from {@see listReports()}.
     */
    public function getReport(string $reportId): Report
    {
        return $this->toReport($this->client->get(
            CybersourceEndpoint::Report->path($reportId),
            'get report',
        ));
    }

    /**
     * Downloads a generated report file (CSV or XML).
     *
     * The file comes back as a raw body rather than JSON, so the request's format is sent as the
     * Accept header and must match the format the report was generated in. The download is keyed
     * by report name and date — the **end** of the period covered, in the report's own timezone —
     * which is the usual cause of a 404 on a report that plainly exists;
     * {@see Report::downloadRequest()} derives both correctly from a listed report.
     */
    public function downloadReport(DownloadReportRequest $request): ReportFile
    {
        return new ReportFile(
            content: $this->client->getBody(
                CybersourceEndpoint::ReportDownloads->path().ReportPayload::downloadQuery($request, $this->organizationId($request->organizationId)),
                $request->format->value,
                'download report',
            ),
            format: $request->format,
            name: $request->name,
            reportDate: $request->reportDate,
        );
    }

    /**
     * Lists every standing report subscription — the schedules, not their individual runs.
     *
     * @param  string|null  $organizationId  Organization to list; defaults to the credentials' organization.
     * @return list<ReportSubscription>
     */
    public function listReportSubscriptions(?string $organizationId = null): array
    {
        $response = $this->client->get(
            CybersourceEndpoint::ReportSubscriptions->path().ReportPayload::organizationQuery($this->organizationId($organizationId)),
            'list report subscriptions',
        );

        $subscriptions = is_array($response['subscriptions'] ?? null) ? $response['subscriptions'] : [];

        return array_values(array_map(
            fn (mixed $subscription): ReportSubscription => $this->toReportSubscription(Value::array($subscription)),
            $subscriptions,
        ));
    }

    /**
     * Fetches one report subscription by its unique report name.
     *
     * @param  string  $reportName  Name the subscription was created under.
     * @param  string|null  $organizationId  Organization the subscription belongs to; defaults to the credentials' organization.
     */
    public function getReportSubscription(string $reportName, ?string $organizationId = null): ReportSubscription
    {
        return $this->toReportSubscription($this->client->get(
            CybersourceEndpoint::ReportSubscription->path(rawurlencode($reportName)).ReportPayload::organizationQuery($this->organizationId($organizationId)),
            'get report subscription',
        ));
    }

    /**
     * Creates — or replaces — the schedule that generates a report repeatedly.
     *
     * The endpoint is a PUT keyed by report name, so creating a subscription under a name that
     * already exists overwrites that schedule rather than failing. CyberSource returns an empty
     * body, so acceptance is all there is to report; read the stored schedule back with
     * {@see getReportSubscription()}.
     *
     * @return bool True once CyberSource has accepted the subscription.
     */
    public function createReportSubscription(CreateReportSubscriptionRequest $request): bool
    {
        $this->client->put(
            CybersourceEndpoint::ReportSubscriptions->path(),
            ReportPayload::subscription($request, $this->organizationId($request->organizationId)),
            'create report subscription',
        );

        return true;
    }

    /**
     * Deletes a report subscription, stopping future runs of that scheduled report.
     *
     * Reports the schedule has already generated are untouched and stay downloadable.
     *
     * @param  string  $reportName  Name of the subscription to delete.
     * @param  string|null  $organizationId  Organization the subscription belongs to; defaults to the credentials' organization.
     * @return bool True once CyberSource has accepted the deletion.
     */
    public function deleteReportSubscription(string $reportName, ?string $organizationId = null): bool
    {
        $this->client->delete(
            CybersourceEndpoint::ReportSubscription->path(rawurlencode($reportName)).ReportPayload::organizationQuery($this->organizationId($organizationId)),
            'delete report subscription',
        );

        return true;
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
     * Subscribes one of your endpoints to CyberSource notifications.
     *
     * The other half of {@see verifyWebhook()}: that verifies what arrives, this decides what is
     * sent and where. Create a signing key with {@see createWebhookSecurityKey()} **first** — the
     * gateway signs each notification with it and it is the same secret `verifyWebhook()` checks,
     * so a subscription made before the key exists has nothing to sign with. Supply a health-check
     * URL if you can: without one, a subscription the gateway suspends after repeated delivery
     * failures must be reactivated by hand. This is a CyberSource-specific operation, outside the
     * shared {@see PaymentGatewayInterface}.
     */
    public function createWebhook(CreateWebhookRequest $request): WebhookSubscription
    {
        return $this->toWebhookSubscription($this->client->post(
            CybersourceEndpoint::Webhooks->path(),
            WebhookPayload::create($request, $this->organizationId($request->organizationId)),
            'create webhook',
        ));
    }

    /**
     * Lists the webhook subscriptions registered for an organization.
     *
     * @param  string|null  $productId  Only subscriptions covering this product.
     * @param  string|null  $eventType  Only subscriptions covering this event type.
     * @param  string|null  $organizationId  Organization to list; defaults to the credentials' organization.
     * @return list<WebhookSubscription>
     */
    public function listWebhooks(?string $productId = null, ?string $eventType = null, ?string $organizationId = null): array
    {
        $query = array_filter([
            'organizationId' => $this->organizationId($organizationId),
            'productId' => $productId,
            'eventType' => $eventType,
        ], filled(...));

        $response = $this->client->get(
            CybersourceEndpoint::Webhooks->path().'?'.http_build_query($query),
            'list webhooks',
        );

        $subscriptions = array_is_list($response) ? $response : Value::array($response['webhooks'] ?? null);

        return array_values(array_map(
            fn (mixed $webhook): WebhookSubscription => $this->toWebhookSubscription(Value::array($webhook)),
            $subscriptions,
        ));
    }

    /**
     * Fetches one webhook subscription by id.
     *
     * @param  string  $webhookId  CyberSource webhook identifier.
     */
    public function getWebhook(string $webhookId): WebhookSubscription
    {
        return $this->toWebhookSubscription($this->client->get(
            CybersourceEndpoint::Webhook->path($webhookId),
            'get webhook',
        ));
    }

    /**
     * Amends a webhook subscription in place — a partial update.
     *
     * Changing the delivery state is a separate call: use {@see setWebhookStatus()}.
     */
    public function updateWebhook(UpdateWebhookRequest $request): WebhookSubscription
    {
        return $this->toWebhookSubscription($this->client->patch(
            CybersourceEndpoint::Webhook->path($request->webhookId),
            WebhookPayload::update($request),
            'update webhook',
        ));
    }

    /**
     * Activates or deactivates a webhook subscription.
     *
     * Deactivating stops delivery without discarding the subscription or its history, which is the
     * safe way to pause an endpoint you are redeploying — deleting it would lose the configuration.
     *
     * @param  string  $webhookId  CyberSource webhook identifier.
     * @param  WebhookStatus  $status  The delivery state to move it to.
     * @return bool True once CyberSource has accepted the change.
     */
    public function setWebhookStatus(string $webhookId, WebhookStatus $status): bool
    {
        $this->client->put(
            CybersourceEndpoint::WebhookStatus->path($webhookId),
            WebhookPayload::status($status),
            'set webhook status',
        );

        return true;
    }

    /**
     * Deletes a webhook subscription, stopping delivery permanently.
     *
     * The notification history is kept; only the subscription goes. Prefer
     * {@see setWebhookStatus()} to pause an endpoint you intend to bring back.
     *
     * @param  string  $webhookId  CyberSource webhook identifier to delete.
     * @return bool True once CyberSource has accepted the deletion.
     */
    public function deleteWebhook(string $webhookId): bool
    {
        $this->client->delete(CybersourceEndpoint::Webhook->path($webhookId), 'delete webhook');

        return true;
    }

    /**
     * Sends a sample notification to a subscription's endpoint, to prove the wiring works.
     *
     * The sample carries representative product and event values drawn from the subscription, so a
     * receiver can be validated — including its signature verification — before any real payment
     * depends on it.
     *
     * @param  string  $webhookId  CyberSource webhook identifier to test.
     * @return array<string, mixed> The raw test result, including how your endpoint responded.
     */
    public function testWebhook(string $webhookId): array
    {
        return $this->client->postWithoutBody(
            CybersourceEndpoint::WebhookTest->path($webhookId),
            'test webhook',
        );
    }

    /**
     * Lists the products and event types this organization may subscribe to.
     *
     * Which are available depends on the account's entitlements, so discover them here rather than
     * hard-coding product ids and event names into a subscription.
     *
     * @param  string|null  $organizationId  Organization to inspect; defaults to the credentials' organization.
     * @return array<int|string, mixed> The raw catalogue; each entry pairs a product with its event types.
     */
    public function listWebhookProducts(?string $organizationId = null): array
    {
        return $this->client->get(
            CybersourceEndpoint::WebhookProducts->path($this->organizationId($organizationId)),
            'list webhook products',
        );
    }

    /**
     * Creates the symmetric key CyberSource signs webhook notifications with.
     *
     * This is the missing half of webhook onboarding: the key it returns is the value the
     * `webhook_secret` credential holds, and the one {@see verifyWebhook()} checks signatures
     * against. It is shown **once** — store {@see WebhookSecurityKey::$key} immediately, because
     * it cannot be read back and a subscription signed with a key you lost is unverifiable.
     *
     * @param  array<string, mixed>  $keyInformation  Key attributes (provider, tenant, key type) as CyberSource's webhook guide specifies for your account.
     * @param  string  $action  `CREATE` to have CyberSource generate the key, or `STORE`/`REFRESH` to supply or rotate one.
     * @param  string|null  $organizationId  Organization the key belongs to; defaults to the credentials' organization.
     */
    public function createWebhookSecurityKey(array $keyInformation, string $action = 'CREATE', ?string $organizationId = null): WebhookSecurityKey
    {
        $keyInformation['organizationId'] ??= $this->organizationId($organizationId);

        $response = $this->client->post(
            CybersourceEndpoint::WebhookSymmetricKeys->path(),
            WebhookPayload::securityKey($action, $keyInformation),
            'create webhook security key',
        );

        $key = Value::array($response['keyInformation'] ?? null);

        return new WebhookSecurityKey(
            keyId: Value::nullableString($key['keyId'] ?? $response['keyId'] ?? null),
            key: Value::nullableString($key['key'] ?? $response['key'] ?? null),
            status: Value::nullableString($key['status'] ?? null),
            keyType: Value::nullableString($key['keyType'] ?? null),
            organizationId: Value::nullableString($key['organizationId'] ?? null),
            expiryDuration: Value::nullableString($key['expiryDuration'] ?? null),
            raw: $response,
        );
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
     * Maps a CyberSource Recurring Billing JSON response into a SubscriptionResult DTO.
     *
     * Reads the subscription's own lifecycle state from `subscriptionInformation.status` and
     * the gateway's verdict on the call from the top-level `status`, which a lookup omits
     * entirely. Identifiers, plan, name, and start date come from the same subscription block,
     * and the merchant reference from `clientReferenceInformation.code`.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource subscription response body.
     */
    private function toSubscriptionResult(array $response): SubscriptionResult
    {
        $information = Value::array($response['subscriptionInformation'] ?? null);

        $status = CybersourceSubscriptionStatus::toSubscriptionStatusOrNull(
            Value::nullableString($information['status'] ?? null),
        );
        $requestStatus = Value::nullableString($response['status'] ?? null);

        return new SubscriptionResult(
            success: $this->subscriptionSucceeded($requestStatus, $status),
            status: $status,
            subscriptionId: Value::nullableString($response['id'] ?? null),
            subscriptionCode: Value::nullableString($information['code'] ?? null),
            planId: Value::nullableString($information['planId'] ?? null),
            name: Value::nullableString($information['name'] ?? null),
            startDate: Value::nullableString($information['startDate'] ?? null),
            orderReference: Value::nullableString(data_get($response, 'clientReferenceInformation.code')),
            requestStatus: $requestStatus,
            raw: $response,
        );
    }

    /**
     * Maps a CyberSource getAllSubscriptions JSON response into a SubscriptionPage DTO.
     *
     * Each entry under `subscriptions` carries the same shape as a single-subscription lookup, so
     * they are mapped by {@see toSubscriptionResult()}. `totalCount` is the size of the whole
     * filtered set, not of this page; the window is echoed from the request that produced it, since
     * the response does not repeat it.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource subscription-list response body.
     * @param  ListSubscriptionsRequest  $request  The request that produced this page, for its window.
     */
    private function toSubscriptionPage(array $response, ListSubscriptionsRequest $request): SubscriptionPage
    {
        $subscriptions = is_array($response['subscriptions'] ?? null) ? $response['subscriptions'] : [];

        return new SubscriptionPage(
            subscriptions: array_values(array_map(
                fn (mixed $subscription): SubscriptionResult => $this->toSubscriptionResult(Value::array($subscription)),
                $subscriptions,
            )),
            totalCount: Value::int($response['totalCount'] ?? null),
            offset: $request->offset,
            limit: $request->limit,
            raw: $response,
        );
    }

    /**
     * Whether a Recurring Billing response reports a successful outcome.
     *
     * A failed subscription is never a success, whatever the request status says. Otherwise a
     * write is judged by CyberSource's request status — COMPLETED for a create, update, or
     * reactivate, ACCEPTED for a cancel or suspend, and PENDING_REVIEW for an update accepted but
     * held for review (taken as successful, like every other pending state in the SDK; read
     * {@see SubscriptionResult::$requestStatus} to tell it apart). DECLINED and INVALID_REQUEST
     * are refusals. A lookup carries no request status, so it succeeds once a subscription state
     * came back at all.
     *
     * @param  string|null  $requestStatus  Raw top-level status, absent on a lookup.
     * @param  SubscriptionStatus|null  $status  Normalised state of the subscription itself.
     */
    private function subscriptionSucceeded(?string $requestStatus, ?SubscriptionStatus $status): bool
    {
        if ($status === SubscriptionStatus::Failed) {
            return false;
        }

        if ($requestStatus === null) {
            return $status instanceof SubscriptionStatus;
        }

        return in_array(strtoupper($requestStatus), ['COMPLETED', 'ACCEPTED', 'PENDING_REVIEW'], true);
    }

    /**
     * Maps a CyberSource Account Updater batch JSON record into an AccountUpdaterBatch DTO.
     *
     * The submit response carries only the batch id and status, while a status or list record
     * adds the totals; both shapes read the same way, with absent counts defaulting to zero.
     *
     * @param  array<string, mixed>  $response  Decoded Account Updater batch record.
     */
    private function toAccountUpdaterBatch(array $response): AccountUpdaterBatch
    {
        $totals = Value::array($response['totals'] ?? null);

        return new AccountUpdaterBatch(
            batchId: Value::nullableString($response['batchId'] ?? $response['id'] ?? null),
            status: AccountUpdaterBatchStatus::tryFrom(strtoupper(Value::string($response['status'] ?? null))),
            createdDate: Value::nullableString($response['batchCreatedDate'] ?? null),
            merchantReference: Value::nullableString($response['merchantReference'] ?? null),
            source: Value::nullableString($response['batchSource'] ?? null),
            acceptedRecords: Value::int($totals['acceptedRecords'] ?? null),
            rejectedRecords: Value::int($totals['rejectedRecords'] ?? null),
            updatedRecords: Value::int($totals['updatedRecords'] ?? null),
            networkResponses: Value::int($totals['caResponses'] ?? null),
            raw: $response,
        );
    }

    /**
     * Maps a CyberSource notification-subscription JSON record into a WebhookSubscription DTO.
     *
     * @param  array<string, mixed>  $response  Decoded webhook-subscription record.
     */
    private function toWebhookSubscription(array $response): WebhookSubscription
    {
        $products = is_array($response['products'] ?? null) ? $response['products'] : [];

        return new WebhookSubscription(
            webhookId: Value::nullableString($response['webhookId'] ?? $response['id'] ?? null),
            name: Value::nullableString($response['name'] ?? null),
            description: Value::nullableString($response['description'] ?? null),
            webhookUrl: Value::nullableString($response['webhookUrl'] ?? null),
            healthCheckUrl: Value::nullableString($response['healthCheckUrl'] ?? null),
            status: WebhookStatus::tryFrom(strtoupper(Value::string($response['status'] ?? null))),
            securityType: WebhookSecurityType::tryFrom(Value::string(data_get($response, 'securityPolicy.securityType'))),
            products: array_values(array_map(
                static fn (mixed $product): WebhookProduct => new WebhookProduct(
                    productId: Value::string(data_get($product, 'productId')),
                    eventTypes: array_values(array_map(
                        static fn (mixed $event): string => Value::string($event),
                        is_array(data_get($product, 'eventTypes')) ? Value::array(data_get($product, 'eventTypes')) : [],
                    )),
                ),
                $products,
            )),
            notificationScope: Value::nullableString($response['notificationScope'] ?? null),
            organizationId: Value::nullableString($response['organizationId'] ?? null),
            raw: $response,
        );
    }

    /**
     * Maps a CyberSource TMS payment-instrument JSON record into a PaymentInstrument DTO.
     *
     * The masked card number is not on the instrument itself — it belongs to the linked
     * instrument identifier — so it is read from the embedded identifier when the vault
     * returned one, and left null otherwise.
     *
     * @param  array<string, mixed>  $response  Decoded TMS payment-instrument record.
     * @param  string|null  $customerId  Customer the instrument was read under, which the record omits.
     */
    private function toPaymentInstrument(array $response, ?string $customerId = null): PaymentInstrument
    {
        $card = Value::array($response['card'] ?? null);

        $maskedNumber = data_get($response, '_embedded.instrumentIdentifier.card.number')
            ?? data_get($response, 'instrumentIdentifier.card.number');

        return new PaymentInstrument(
            id: Value::nullableString($response['id'] ?? null),
            customerId: $customerId,
            instrumentIdentifierId: Value::nullableString(data_get($response, 'instrumentIdentifier.id')),
            state: PaymentInstrumentState::tryFrom(strtoupper(Value::string($response['state'] ?? null))),
            isDefault: Value::bool($response['default'] ?? false),
            expirationMonth: Value::nullableString($card['expirationMonth'] ?? null),
            expirationYear: Value::nullableString($card['expirationYear'] ?? null),
            cardType: Value::nullableString($card['type'] ?? null),
            maskedNumber: Value::nullableString($maskedNumber),
            billTo: Value::array($response['billTo'] ?? null),
            raw: $response,
        );
    }

    /**
     * Maps a CyberSource Recurring Billing plan JSON response into a PlanResult DTO.
     *
     * Like a subscription response, a plan write reports two statuses: the plan's own state
     * under `planInformation.status` and the gateway's verdict on the call at the top level.
     * A lookup carries only the former.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource plan record.
     */
    private function toPlanResult(array $response): PlanResult
    {
        $information = Value::array($response['planInformation'] ?? null);
        $amountDetails = Value::array(data_get($response, 'orderInformation.amountDetails'));

        $status = PlanStatus::tryFrom(strtoupper(Value::string($information['status'] ?? null)));
        $requestStatus = Value::nullableString($response['status'] ?? null);
        $currency = Value::nullableString($amountDetails['currency'] ?? null);
        $billingCycles = data_get($information, 'billingCycles.total');

        return new PlanResult(
            success: $this->planSucceeded($requestStatus, $status),
            status: $status,
            planId: Value::nullableString($response['id'] ?? null),
            code: Value::nullableString($information['code'] ?? null),
            name: Value::nullableString($information['name'] ?? null),
            description: Value::nullableString($information['description'] ?? null),
            billingPeriod: $this->toBillingPeriod(Value::array($information['billingPeriod'] ?? null)),
            billingCycles: is_numeric($billingCycles) ? (int) $billingCycles : null,
            billingAmount: $this->toPlanMoney($amountDetails['billingAmount'] ?? null, $currency),
            setupFee: $this->toPlanMoney($amountDetails['setupFee'] ?? null, $currency),
            requestStatus: $requestStatus,
            raw: $response,
        );
    }

    /**
     * Rebuilds a BillingPeriod from a plan's `billingPeriod` block, or null when the block is
     * absent or carries a length/unit CyberSource has since added that the SDK does not model.
     *
     * @param  array<string, mixed>  $billingPeriod  The response's billingPeriod block.
     */
    private function toBillingPeriod(array $billingPeriod): ?BillingPeriod
    {
        $unit = BillingPeriodUnit::tryFrom(strtoupper(Value::string($billingPeriod['unit'] ?? null)));
        $length = Value::int($billingPeriod['length'] ?? null);

        if (! $unit instanceof BillingPeriodUnit || $length < 1) {
            return null;
        }

        return new BillingPeriod($length, $unit);
    }

    /**
     * Rebuilds a Money from a plan's decimal amount string and the plan's currency, or null when
     * either is missing.
     *
     * @param  mixed  $amount  Decimal amount string from the response.
     * @param  string|null  $currency  Currency the plan prices in.
     */
    private function toPlanMoney(mixed $amount, ?string $currency): ?Money
    {
        $decimal = Value::nullableString($amount);

        if ($decimal === null || $currency === null) {
            return null;
        }

        return Money::fromDecimalString($decimal, $currency);
    }

    /**
     * Whether a Recurring Billing plan response reports a successful outcome.
     *
     * Mirrors {@see subscriptionSucceeded()}: an inactive plan is still a successful read, so
     * only the request status decides a write, and a lookup succeeds once a plan state came back.
     *
     * @param  string|null  $requestStatus  Raw top-level status, absent on a lookup.
     * @param  PlanStatus|null  $status  Normalised state of the plan itself.
     */
    private function planSucceeded(?string $requestStatus, ?PlanStatus $status): bool
    {
        if ($requestStatus === null) {
            return $status instanceof PlanStatus;
        }

        return in_array(strtoupper($requestStatus), ['COMPLETED', 'ACCEPTED', 'PENDING_REVIEW'], true);
    }

    /**
     * The organization id reporting and validation calls are scoped to.
     *
     * Prefers a value the caller named on the request, then the `organization_id` credential,
     * falling back to the merchant id — which is the organization id for a plain merchant
     * account. A portfolio or partner account whose reports live under a different organization
     * sets `organization_id` in the credential `extra` bag.
     *
     * @param  string|null  $organizationId  Organization named on the request, when any.
     */
    private function organizationId(?string $organizationId = null): string
    {
        if (filled($organizationId)) {
            return $organizationId;
        }

        $configured = $this->gatewayCredentials->extra['organization_id'] ?? null;

        return filled($configured) ? Value::string($configured) : $this->gatewayCredentials->merchantId;
    }

    /**
     * Maps a CyberSource report-definition JSON record into a ReportDefinition DTO.
     *
     * CyberSource spells the name field `reportDefintionName` (its own typo) on a single lookup
     * and `reportDefinitionName` in the catalogue listing, so both are read. Unsupported formats
     * are dropped rather than guessed at.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource report-definition record.
     */
    private function toReportDefinition(array $response): ReportDefinition
    {
        $formats = is_array($response['supportedFormats'] ?? null) ? $response['supportedFormats'] : [];
        $attributes = is_array($response['attributes'] ?? null) ? $response['attributes'] : [];

        return new ReportDefinition(
            name: Value::nullableString($response['reportDefintionName'] ?? $response['reportDefinitionName'] ?? null),
            id: isset($response['reportDefinitionId']) ? Value::int($response['reportDefinitionId']) : null,
            description: Value::nullableString($response['description'] ?? null),
            type: Value::nullableString($response['type'] ?? null),
            subscriptionType: ReportSubscriptionType::tryFrom(strtoupper(Value::string($response['subscriptionType'] ?? null))),
            supportedFormats: array_values(array_filter(array_map(
                static fn (mixed $format): ?ReportFormat => ReportFormat::tryFrom(strtolower(Value::string($format))),
                $formats,
            ))),
            fields: array_values(array_map(
                fn (mixed $attribute): ReportDefinitionField => $this->toReportDefinitionField(Value::array($attribute)),
                $attributes,
            )),
            raw: $response,
        );
    }

    /**
     * Maps one report-definition attribute into a ReportDefinitionField DTO.
     *
     * @param  array<string, mixed>  $attribute  Decoded CyberSource definition attribute.
     */
    private function toReportDefinitionField(array $attribute): ReportDefinitionField
    {
        return new ReportDefinitionField(
            name: Value::nullableString($attribute['name'] ?? null),
            id: Value::nullableString($attribute['id'] ?? null),
            description: Value::nullableString($attribute['description'] ?? null),
            isRequired: Value::bool($attribute['required'] ?? false),
            isDefault: Value::bool($attribute['default'] ?? false),
            filterType: Value::nullableString($attribute['filterType'] ?? null),
            supportedValues: Value::nullableString($attribute['supportedType'] ?? null),
            raw: $attribute,
        );
    }

    /**
     * Maps a CyberSource reporting JSON record into a Report DTO.
     *
     * Handles both shapes the reporting service returns: a search hit nests its status under
     * `status`, while a single-report lookup nests the same value under `reportStatus`. The
     * generation timestamp is likewise read from either spelling.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource report record.
     */
    private function toReport(array $response): Report
    {
        return new Report(
            reportId: Value::nullableString($response['reportId'] ?? null),
            name: Value::nullableString($response['reportName'] ?? null),
            definitionId: Value::nullableString($response['reportDefinitionId'] ?? null),
            status: ReportStatus::tryFrom(strtoupper(Value::string($response['status'] ?? $response['reportStatus'] ?? null))),
            frequency: ReportFrequency::tryFrom(strtoupper(Value::string($response['reportFrequency'] ?? null))),
            format: ReportFormat::tryFrom(strtolower(Value::string($response['reportMimeType'] ?? null))),
            startTime: Value::nullableString($response['reportStartTime'] ?? null),
            endTime: Value::nullableString($response['reportEndTime'] ?? null),
            completedTime: Value::nullableString($response['reportCompletedTime'] ?? $response['reportGenerationTime'] ?? null),
            timezone: Value::nullableString($response['timezone'] ?? null),
            organizationId: Value::nullableString($response['organizationId'] ?? null),
            subscriptionType: Value::nullableString($response['subscriptionType'] ?? null),
            raw: $response,
        );
    }

    /**
     * Maps a CyberSource report-subscription JSON record into a ReportSubscription DTO.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource subscription record.
     */
    private function toReportSubscription(array $response): ReportSubscription
    {
        $fields = is_array($response['reportFields'] ?? null) ? $response['reportFields'] : [];

        return new ReportSubscription(
            name: Value::nullableString($response['reportName'] ?? null),
            definitionName: Value::nullableString($response['reportDefinitionName'] ?? null),
            frequency: ReportFrequency::tryFrom(strtoupper(Value::string($response['reportFrequency'] ?? null))),
            format: ReportFormat::tryFrom(strtolower(Value::string($response['reportMimeType'] ?? null))),
            startTime: Value::nullableString($response['startTime'] ?? null),
            startDay: isset($response['startDay']) ? Value::int($response['startDay']) : null,
            timezone: Value::nullableString($response['timezone'] ?? null),
            fields: array_values(array_map(
                static fn (mixed $field): string => Value::string($field),
                $fields,
            )),
            subscriptionType: Value::nullableString($response['subscriptionType'] ?? null),
            organizationId: Value::nullableString($response['organizationId'] ?? null),
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
     * A completed authentication (no step-up pending) is additionally rejected when its ECI
     * is not fully authenticated ({@see enforceAuthenticatedEci()}) so an attempted or
     * not-authenticated 3DS result never carries a spurious liability shift into the charge.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource authentication response.
     */
    private function toPayerAuthResult(array $response): PayerAuthResult
    {
        $consumerAuth = Value::array($response['consumerAuthenticationInformation'] ?? null);

        $status = Value::string($response['status'] ?? null);

        $stepUpUrl = Value::nullableString($consumerAuth['stepUpUrl'] ?? null);

        $success = filled($status) && ! in_array($status, ['AUTHENTICATION_FAILED', 'INVALID_REQUEST', 'FAILED'], true);

        return new PayerAuthResult(
            success: $success && $this->enforceAuthenticatedEci($success, $stepUpUrl, $consumerAuth),
            status: $status,
            stepUpUrl: $stepUpUrl,
            accessToken: Value::nullableString($consumerAuth['accessToken'] ?? null),
            authenticationTransactionId: Value::nullableString($consumerAuth['authenticationTransactionId'] ?? null),
            consumerAuthenticationInformation: $consumerAuth,
            raw: $response,
        );
    }

    /**
     * Whether a completed authentication's ECI clears the fully-authenticated bar.
     *
     * Only a completed authentication is checked: a pending step-up challenge (a step-up URL is
     * present) carries no final ECI yet, and a response already marked unsuccessful needs no
     * further scrutiny — both return true here so the caller's own success flag stands. When a
     * completed result carries an ECI that is not fully authenticated (an attempted 01/06 or a
     * not-authenticated 00/07), it is rejected: this returns false and a
     * {@see PayerAuthenticationEciRejected} event is dispatched. An absent ECI is not rejected.
     *
     * @param  array<string, mixed>  $consumerAuth  The response's consumerAuthenticationInformation block.
     */
    private function enforceAuthenticatedEci(bool $success, ?string $stepUpUrl, array $consumerAuth): bool
    {
        if (! $success || $stepUpUrl !== null) {
            return true;
        }

        $eci = CybersourceEci::fromConsumerAuthentication($consumerAuth);

        if (! $eci instanceof CybersourceEci || $eci->isFullyAuthenticated()) {
            return true;
        }

        $this->events?->dispatch(new PayerAuthenticationEciRejected(
            gateway: $this->name(),
            eci: $eci->value,
            acceptedEci: CybersourceEci::FULLY_AUTHENTICATED,
            outcome: $eci->outcome(),
            authenticationTransactionId: Value::nullableString($consumerAuth['authenticationTransactionId'] ?? null),
            status: PaymentStatus::Declined,
        ));

        return false;
    }

    /**
     * Maps a CyberSource payer-authentication setup (3DS DDC) JSON response into a
     * PayerAuthSetupResult DTO.
     *
     * Extracts the consumer-authentication block to surface the access token, reference id,
     * and device-data-collection URL, and marks success unless the raw status is
     * INVALID_REQUEST or FAILED.
     *
     * @param  array<string, mixed>  $response  Decoded CyberSource authentication-setup response.
     */
    private function toPayerAuthSetupResult(array $response): PayerAuthSetupResult
    {
        $consumerAuth = Value::array($response['consumerAuthenticationInformation'] ?? null);

        $status = Value::string($response['status'] ?? null);

        return new PayerAuthSetupResult(
            success: filled($status) && ! in_array($status, ['INVALID_REQUEST', 'FAILED'], true),
            status: $status,
            accessToken: Value::nullableString($consumerAuth['accessToken'] ?? null),
            referenceId: Value::nullableString($consumerAuth['referenceId'] ?? null),
            deviceDataCollectionUrl: Value::nullableString($consumerAuth['deviceDataCollectionUrl'] ?? null),
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
