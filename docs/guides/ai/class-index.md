# Complete class index

Every one of the 186 classes, interfaces, enums, and traits in `hyprpay/payments`, grouped by namespace (prefix `Hyprpay\Payments\` omitted). Each line is the type and its one-line purpose taken from the source. This index is exhaustive — nothing in `src/` is omitted.

## `Application`

- **`PaymentGatewayFactory`** _class_ — Composition-edge factory that constructs concrete gateway drivers.
- **`ReconciliationOutcome`** _class_ — Outcome of reconciling a single transaction id against its gateway.
- **`TransactionReconciler`** _class_ — Application use-case that reconciles transactions against their gateway.

## `Domain`

- **`AbstractPaymentGateway`** _class_ — Base class for gateway drivers implementing the PaymentGatewayInterface port.

## `Domain\Command`

- **`CaptureRequest`** _class_ — Input DTO for capturing funds on a previously authorised transaction.
- **`ChargeRequest`** _class_ — Input DTO for charging a card captured by the Unified Checkout widget.
- **`CheckoutOptions`** _interface_ — A typed, gateway-specific bag of checkout options carried by a CheckoutSessionRequest.
- **`CheckoutSessionRequest`** _class_ — Input DTO for starting a checkout/payment session across gateways.
- **`ConfirmOrchestratedPaymentRequest`** _class_ — Input DTO for confirming a Unified Checkout v1 orchestrated (autoProcessing) payment.
- **`DccRateRequest`** _class_ — Input DTO for requesting a Dynamic Currency Conversion (DCC) rate quote.
- **`PayerAuthEnrollRequest`** _class_ — Input DTO for enrolling a card into 3-D Secure payer authentication.
- **`RefundRequest`** _class_ — Input DTO for refunding funds from a settled/captured transaction.
- **`ReversalRequest`** _class_ — Input DTO for reversing (releasing) a prior authorisation.
- **`StoredCredentialChargeRequest`** _class_ — Input DTO for charging a previously vaulted payment instrument (stored credential).
- **`TokenizeInstrumentRequest`** _class_ — Input DTO for tokenising (vaulting) a raw card into a reusable payment instrument.
- **`ValidatePayerAuthRequest`** _class_ — Input DTO for validating a 3-D Secure authentication after a challenge (step-up).
- **`VoidRequest`** _class_ — Input DTO for voiding an uncaptured transaction.

## `Domain\Contract`

- **`CredentialResolver`** _interface_ — Port that supplies gateway credentials for a given gateway.
- **`EventDispatcher`** _interface_ — Port that dispatches the SDK's payment domain events to the host application.
- **`HttpClient`** _interface_ — Outbound-HTTP port used by gateway drivers to reach remote gateway APIs.
- **`PaymentGatewayInterface`** _interface_ — The port that every payment gateway driver in the SDK implements.

## `Domain\Enum`

- **`CredentialInitiator`** _enum_ — Identifies who initiated a stored-credential (card-on-file) transaction.
- **`GatewayName`** _enum_ — Canonical identifier for each payment gateway the SDK can drive.
- **`MandateCompletionType`** _enum_ — Orchestration mode requested from the CyberSource Unified Checkout v1 widget.
- **`PaymentStatus`** _enum_ — Normalized, gateway-agnostic lifecycle status of a payment.

## `Domain\Event`

- **`AuthorizationReversed`** _class_ — Emitted after an existing authorization is reversed (its held funds released).
- **`CheckoutSessionCreated`** _class_ — Emitted after a checkout session is created for the customer to complete payment.
- **`InstrumentVaulted`** _class_ — Emitted after a payment instrument is tokenized (vaulted) for later reuse.
- **`PaymentCaptured`** _class_ — Emitted after a capture of a previously authorized payment completes.
- **`PaymentCharged`** _class_ — Emitted after a charge completes; inspect the result's success/status for the outcome.
- **`PaymentEvent`** _interface_ — Marker interface implemented by every payment domain event the SDK emits.
- **`PaymentRefunded`** _class_ — Emitted after a refund of a settled payment completes.
- **`PaymentVoided`** _class_ — Emitted after an authorized-but-uncaptured payment is voided.
- **`StoredCredentialCharged`** _class_ — Emitted after a charge against a stored (vaulted) credential completes.
- **`WebhookReceived`** _class_ — Emitted after an inbound webhook is verified and parsed.

## `Domain\Exception`

- **`GatewayException`** _class_ — Base exception for all payment gateway errors raised by the SDK.
- **`GatewayNotSupportedException`** _class_ — Thrown when a gateway is requested by name but no driver is registered for it.
- **`GatewayRequestException`** _class_ — Thrown when an HTTP call to the gateway API returns an error (non-2xx) response.
- **`MissingCredentialsException`** _class_ — Thrown when a gateway's required credentials are absent or incomplete.
- **`PaymentVerificationException`** _class_ — Thrown when a client-supplied orchestrated-payment result JWT cannot be trusted.
- **`UnsupportedOperationException`** _class_ — Thrown when a gateway driver does not implement a requested operation.
- **`WebhookVerificationException`** _class_ — Thrown when an inbound webhook fails signature or authenticity verification.

## `Domain\Http`

- **`HttpRequest`** _class_ — Immutable value object describing an outbound HTTP request to a gateway API.
- **`HttpResponse`** _class_ — Immutable value object holding the result of an outbound gateway HTTP request.

## `Domain\Result`

- **`CheckoutSession`** _class_ — Result DTO describing how the customer completes a started payment.
- **`DccQuote`** _class_ — Result DTO describing a Dynamic Currency Conversion (DCC) rate quote.
- **`OrchestratedPaymentResult`** _class_ — Outcome of a verified Unified Checkout v1 orchestrated (autoProcessing) payment.
- **`PayerAuthResult`** _class_ — Result DTO returned by a 3-D Secure enrol or validate operation.
- **`PaymentResult`** _class_ — Result DTO describing the normalised outcome of a charge, capture, void, or reversal.
- **`RefundResult`** _class_ — Result DTO describing the normalised outcome of a refund operation.
- **`TransactionSnapshot`** _class_ — Result DTO representing the current state of a transaction as fetched from the gateway.
- **`VaultedInstrument`** _class_ — Result DTO returned after tokenising (vaulting) a card.
- **`WebhookEvent`** _class_ — Result DTO representing a parsed and signature-verified inbound gateway webhook.

## `Domain\ValueObject`

- **`BillingAddress`** _class_ — Value object holding the payer's billing contact and postal details.
- **`BrowserDeviceData`** _class_ — Value object holding the payer's browser device data for 3-D Secure authentication.
- **`Customer`** _class_ — Value object identifying the customer behind a payment.
- **`GatewayCredentials`** _class_ — Immutable DTO holding the per-gateway credentials and settings a driver needs.
- **`Money`** _class_ — Immutable money value object holding an amount in minor units plus its currency.

## `Infrastructure`

- **`GatewayServiceProvider`** _class_ — Laravel service provider that wires the gateway SDK into the container.

## `Infrastructure\Console`

- **`ReconcileAuthorizeNetCommand`** _class_ — Artisan command that reconciles Authorize.Net transactions.
- **`ReconcileCommand`** _class_ — Base Artisan command that reconciles a gateway's transactions by id.
- **`ReconcileCybersourceCommand`** _class_ — Artisan command that reconciles CyberSource Unified Checkout transactions.
- **`ReconcileFawryCommand`** _class_ — Artisan command that reconciles Fawry transactions.
- **`ReconcileMpgsCommand`** _class_ — Artisan command that reconciles MPGS orders.
- **`ReconcilePayPalCommand`** _class_ — Artisan command that reconciles PayPal transactions.
- **`ReconcilePaylinkCommand`** _class_ — Artisan command that reconciles PayLink transactions.
- **`ReconcilePaymobCommand`** _class_ — Artisan command that reconciles Paymob transactions.
- **`ReconcilePaytabsCommand`** _class_ — Artisan command that reconciles PayTabs transactions.

## `Infrastructure\Credentials`

- **`ConfigCredentialResolver`** _class_ — Credential resolver that reads gateway settings from Laravel's config repository.

## `Infrastructure\Events`

- **`LaravelEventDispatcher`** _class_ — EventDispatcher adapter that forwards payment events to Laravel's event dispatcher.
- **`LoggingPaymentEventListener`** _class_ — Listener that writes a redaction-safe audit line for every payment event via PSR-3.
- **`RecordingEventDispatcher`** _class_ — In-memory test double implementing the {@see EventDispatcher} port.

## `Infrastructure\Gateway`

- **`EventDispatchingGateway`** _class_ — Decorator that dispatches a payment domain event after each lifecycle operation.
- **`LoggingGateway`** _class_ — Decorator that logs every gateway operation with its duration through the LogsAction trait.

## `Infrastructure\Gateway\AuthorizeNet`

- **`AuthorizeNetClient`** _class_ — Sends requests to the Authorize.Net JSON API (the single `/xml/v1/request.api` endpoint).
- **`AuthorizeNetGateway`** _class_ — Authorize.Net (Authorize.net) payment gateway adapter.

## `Infrastructure\Gateway\AuthorizeNet\Enums`

- **`AuthorizeNetTransactionStatus`** _enum_ — Maps Authorize.Net's getTransactionDetails `transactionStatus` values to the SDK's PaymentStatus.

## `Infrastructure\Gateway\AuthorizeNet\Payloads`

- **`AuthorizeNetPayload`** _class_ — Builds the `transactionRequest` bodies for Authorize.Net's createTransactionRequest calls.

## `Infrastructure\Gateway\CybersourceUnifiedCheckout`

- **`CybersourceClient`** _class_ — Low-level HTTP client for the CyberSource REST API.
- **`CybersourceUnifiedCheckoutGateway`** _class_ — CyberSource Unified Checkout payment gateway adapter.

## `Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns`

- **`ParsesTransientToken`** _trait_ — Decodes CyberSource Unified Checkout transient-token and capture-context JWTs.
- **`SignsCybersourceRequests`** _trait_ — Builds CyberSource HMAC-SHA256 HTTP Signature authentication headers.
- **`VerifiesCybersourceWebhook`** _trait_ — Verifies CyberSource webhook notification signatures.
- **`VerifiesResultJwt`** _trait_ — Cryptographically verifies Unified Checkout v1 orchestrated-payment result JWTs.

## `Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums`

- **`CybersourceEndpoint`** _enum_ — CyberSource REST API resource paths used by the Unified Checkout gateway.
- **`CybersourcePaymentType`** _enum_ — Payment types (`allowedPaymentTypes`) offered by a CyberSource Unified Checkout
- **`CybersourceTransactionStatus`** _enum_ — Raw transaction status strings returned by the CyberSource REST API, with a

## `Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads`

- **`CaptureContextPayload`** _class_ — Builds the CyberSource Unified Checkout capture-context request body.
- **`CapturePayload`** _class_ — Builds the CyberSource capture request body.
- **`ClientReference`** _class_ — Derives the CyberSource clientReferenceInformation.code correlation value.
- **`CurrencyConversionPayload`** _class_ — Builds the CyberSource DCC rate-inquiry (currency conversion) request body.
- **`DccAmountDetails`** _class_ — Produces the Dynamic Currency Conversion fields added to a CyberSource amountDetails block.
- **`DeviceInformation`** _class_ — Produces the CyberSource deviceInformation block carrying the Decision Manager device fingerprint.
- **`PayerAuthEnrollPayload`** _class_ — Builds the CyberSource Payer Authentication enrollment (3DS) request body.
- **`PayerAuthValidatePayload`** _class_ — Builds the CyberSource Payer Authentication results (3DS) request body.
- **`PaymentPayload`** _class_ — Builds the CyberSource payments (authorization/charge) request body for a Unified Checkout charge.
- **`RefundPayload`** _class_ — Builds the CyberSource refund request body.
- **`ReversalPayload`** _class_ — Builds the CyberSource authorization reversal request body.
- **`SearchPayload`** _class_ — Builds the CyberSource Transaction Search (TSS) request body.
- **`StoredCredentialPayload`** _class_ — Builds the CyberSource payments request body for a stored-credential charge.
- **`TokenizePayload`** _class_ — Builds the CyberSource Token Management Service (TMS) request bodies used to save a card.
- **`VoidPayload`** _class_ — Builds the CyberSource void request body.

## `Infrastructure\Gateway\Fawry`

- **`FawryCard`** _class_ — Raw card details for a Fawry card (PayUsingCC / instalment) charge.
- **`FawryCheckoutOptions`** _class_ — Typed checkout options for the Fawry gateway.
- **`FawryClient`** _class_ — Sends HTTP requests to the FawryPay REST endpoints via the SDK's HttpClient port.
- **`FawryGateway`** _class_ — FawryPay payment gateway adapter.
- **`FawrySignature`** _class_ — Builds FawryPay request/response SHA-256 signatures.

## `Infrastructure\Gateway\Fawry\Enums`

- **`FawryEndpoint`** _enum_ — The FawryPay REST endpoints the driver calls, with environment-aware URLs.
- **`FawryOrderStatus`** _enum_ — FawryPay order/payment status values, mapped to the SDK's normalized status.
- **`FawryPaymentMethod`** _enum_ — The FawryPay checkout flows the driver supports.

## `Infrastructure\Gateway\Fawry\Payloads`

- **`FawryCancelPayload`** _class_ — Builds the FawryPay Cancel Payment Authorization request body.
- **`FawryCapturePayload`** _class_ — Builds the FawryPay Auth/Capture capture request body.
- **`FawryChargePayload`** _class_ — Builds the FawryPay charge request bodies for the direct payment methods.
- **`FawryFields`** _class_ — Derives the shared FawryPay request fields from a CheckoutSessionRequest.
- **`FawryHostedPayload`** _class_ — Builds the FawryPay Express Checkout hosted-page init request body.
- **`FawryRefundPayload`** _class_ — Builds the FawryPay refund request body.

## `Infrastructure\Gateway\Mpgs`

- **`MpgsCheckoutOptions`** _class_ — Typed checkout options for the MPGS Hosted Checkout session.
- **`MpgsClient`** _class_ — Low-level HTTP client for the Mastercard Payment Gateway Services (MPGS) REST API.
- **`MpgsGateway`** _class_ — Mastercard Payment Gateway Services (MPGS) payment gateway adapter.

## `Infrastructure\Gateway\Mpgs\Concerns`

- **`MapsMpgsResponses`** _trait_ — Maps decoded MPGS JSON responses into the SDK's normalized result DTOs.
- **`ResolvesMpgsIdentifiers`** _trait_ — Resolves the MPGS order and transaction identifiers a request maps to.
- **`VerifiesMpgsWebhook`** _trait_ — Verifies inbound MPGS webhook (notification) authenticity.

## `Infrastructure\Gateway\Mpgs\Enums`

- **`MpgsApiOperation`** _enum_ — The `apiOperation` values MPGS accepts in a session or transaction request body.
- **`MpgsEndpoint`** _enum_ — Mastercard Payment Gateway Services (MPGS) REST resource paths.
- **`MpgsGatewayCode`** _enum_ — The `response.gatewayCode` values MPGS returns, mapped to the SDK's PaymentStatus.
- **`MpgsOrderStatus`** _enum_ — The order-level `status` values MPGS reports, mapped to the SDK's PaymentStatus.
- **`MpgsResult`** _enum_ — The top-level `result` field MPGS returns for a transaction request.

## `Infrastructure\Gateway\Mpgs\Payloads`

- **`CapturePayload`** _class_ — Builds the MPGS `CAPTURE` transaction request body.
- **`ChargePayload`** _class_ — Builds the MPGS `PAY`/`AUTHORIZE` transaction request body for a session charge.
- **`HostedCheckoutPayload`** _class_ — Builds the MPGS Hosted Checkout `INITIATE_CHECKOUT` session request body.
- **`MpgsPayloadParts`** _class_ — Shared MPGS request-body fragments reused across the payload builders.
- **`PayerAuthInitiatePayload`** _class_ — Builds the MPGS `INITIATE_AUTHENTICATION` (3-D Secure) request body.
- **`PayerAuthenticatePayload`** _class_ — Builds the MPGS `AUTHENTICATE_PAYER` (3-D Secure) request body.
- **`RefundPayload`** _class_ — Builds the MPGS `REFUND` transaction request body.
- **`StoredCredentialPayload`** _class_ — Builds the MPGS `PAY` transaction request body for a stored-credential (card-on-file) charge.
- **`TokenizePayload`** _class_ — Builds the MPGS token-creation request body.
- **`VoidPayload`** _class_ — Builds the MPGS `VOID` transaction request body.

## `Infrastructure\Gateway\PayPal`

- **`PayPalCheckoutOptions`** _class_ — Typed checkout options for the PayPal order's `payment_source.paypal.experience_context`.
- **`PayPalClient`** _class_ — Sends requests to the PayPal REST API (Orders v2 / Payments v2) via the HttpClient port.
- **`PayPalGateway`** _class_ — PayPal REST (Orders v2 / Payments v2) payment gateway adapter.

## `Infrastructure\Gateway\PayPal\Enums`

- **`PayPalEndpoint`** _enum_ — The PayPal REST API paths the driver calls, appended to the api-m host.
- **`PayPalOrderStatus`** _enum_ — PayPal Orders v2 order-level `status` values, mapped to the SDK's normalized status.
- **`PayPalPaymentMethodPreference`** _enum_ — PayPal `experience_context.payment_method_preference` — which funding sources are allowed.
- **`PayPalPaymentStatus`** _enum_ — PayPal Payments v2 resource `status` values, mapped to the SDK's normalized status.
- **`PayPalShippingPreference`** _enum_ — PayPal `experience_context.shipping_preference` — how PayPal handles the shipping address.
- **`PayPalUserAction`** _enum_ — PayPal `experience_context.user_action` — the label of the approval button PayPal shows.

## `Infrastructure\Gateway\PayPal\Payloads`

- **`PayPalPayload`** _class_ — Builds the PayPal REST request bodies for each operation the driver performs.

## `Infrastructure\Gateway\Paylink`

- **`PaylinkCheckoutOptions`** _class_ — Typed checkout options for the PayLink gateway.
- **`PaylinkClient`** _class_ — Sends signed requests to the PayLink Payment Integration API via the HttpClient port.
- **`PaylinkEndpoint`** _enum_ — The PayLink Payment Integration endpoints the driver signs and calls.
- **`PaylinkGateway`** _class_ — PayLink Payment Integration gateway adapter (pay.getpayin.com).
- **`PaylinkSignature`** _class_ — The PayLink Payment Integration signing primitive.
- **`PaylinkSignedBody`** _class_ — Builds the signed JSON body for a PayLink Payment Integration request.

## `Infrastructure\Gateway\Paylink\Enums`

- **`PaylinkPaidStatus`** _class_ — Maps PayLink invoice paid/authorization statuses to the SDK's normalized status.

## `Infrastructure\Gateway\Paymob`

- **`PaymobCheckoutOptions`** _class_ — Typed checkout options for the Paymob (Accept) gateway.
- **`PaymobClient`** _class_ — Sends requests to the Paymob Accept REST API via the SDK's HttpClient port.
- **`PaymobGateway`** _class_ — Paymob (Accept) payment gateway adapter.
- **`PaymobHmac`** _class_ — Computes the Paymob transaction-callback HMAC.

## `Infrastructure\Gateway\Paymob\Checkout`

- **`PaymobCheckoutContext`** _class_ — Carries state through the Paymob checkout pipeline as each pipe fills in its part.

## `Infrastructure\Gateway\Paymob\Checkout\Pipes`

- **`Authenticate`** _class_ — First checkout step: exchange the merchant API key for a short-lived Paymob auth token.
- **`BuildCheckoutSession`** _class_ — Final checkout step: assemble the CheckoutSession the gateway returns.
- **`RegisterOrder`** _class_ — Second checkout step: register the order with Paymob and capture its id.
- **`RequestPaymentKey`** _class_ — Third checkout step: request the payment key that the iframe redirect is built from.

## `Infrastructure\Gateway\Paymob\Enums`

- **`PaymobPaymentMethod`** _enum_ — The Paymob acceptance methods the driver can start a checkout for.
- **`PaymobTransactionStatus`** _class_ — Derives the SDK's normalized payment status from a Paymob transaction object.

## `Infrastructure\Gateway\Paymob\Payloads`

- **`PaymobBillingData`** _class_ — Builds the Paymob `billing_data` block for the payment-key request.
- **`PaymobOrderPayload`** _class_ — Builds the Paymob order-registration request body (POST /ecommerce/orders).
- **`PaymobPaymentKeyPayload`** _class_ — Builds the Paymob payment-key request body (POST /acceptance/payment_keys).

## `Infrastructure\Gateway\Paytabs`

- **`PaytabsAgreement`** _class_ — A PayTabs repeat-billing agreement ({@see PaytabsCheckoutOptions::$agreement}).
- **`PaytabsBeneficiary`** _class_ — A split-payout beneficiary's bank details ({@see PaytabsSplitPayout::$beneficiary}).
- **`PaytabsCheckoutOptions`** _class_ — Typed checkout options for the PayTabs gateway.
- **`PaytabsClient`** _class_ — Sends requests to the PayTabs PT2 REST endpoints via the SDK's HttpClient port.
- **`PaytabsGateway`** _class_ — PayTabs PT2 payment gateway adapter.
- **`PaytabsLineItem`** _class_ — One invoice line item ({@see PaytabsCheckoutOptions::$lineItems}).
- **`PaytabsSignature`** _class_ — The PayTabs IPN/return callback signing primitive.
- **`PaytabsSplitPayout`** _class_ — One split-payout stakeholder ({@see PaytabsCheckoutOptions::$splitPayout}).

## `Infrastructure\Gateway\Paytabs\Enums`

- **`PaytabsEndpoint`** _enum_ — The PayTabs PT2 REST endpoints the driver calls.
- **`PaytabsResponseStatus`** _enum_ — PayTabs transaction `response_status` codes, mapped to the SDK's normalized status.

## `Infrastructure\Gateway\Paytabs\Payloads`

- **`PaytabsPayload`** _class_ — Builds the PayTabs PT2 request bodies for each operation and integration type.

## `Infrastructure\Http`

- **`FakeHttpClient`** _class_ — In-memory test double implementing the {@see HttpClient} port.
- **`LaravelHttpClient`** _class_ — Adapter that fulfils the {@see HttpClient} port using Laravel's HTTP client.
- **`LoggingHttpClient`** _class_ — HttpClient decorator that logs request/response metadata via a PSR-3 logger.
- **`RateLimitingHttpClient`** _class_ — HttpClient decorator that throttles outbound requests with a token bucket.
- **`RetryingHttpClient`** _class_ — HttpClient decorator that retries transient failures with exponential backoff.

## `Infrastructure\Support`

- **`Value`** _class_ — Typed coercion helpers for values decoded from gateway JSON responses.

## `Infrastructure\Support\Concerns`

- **`LogsAction`** _trait_ — Adds level-based, self-identifying logging to a class through an injected PSR-3 logger.

