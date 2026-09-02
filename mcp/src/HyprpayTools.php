<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Mcp;

use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use PhpMcp\Server\Attributes\McpTool;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * MCP tool surface for the hyprpay/payments SDK.
 *
 * Each method is exposed to AI agents as a tool that reflects the live package —
 * its gateways, operations, request/result DTOs, enums, and value objects — so an
 * agent can discover the SDK and generate correct integration code without guessing.
 * Mirrors the shape of the CyberSource developer MCP (overview, list, class details,
 * code templates) against this package instead.
 */
final class HyprpayTools
{
    private const MONEY_MOVING = [
        'charge', 'capture', 'refund', 'void', 'reverseAuthorization', 'chargeStoredCredential',
    ];

    /**
     * Real-world integration gotchas surfaced by {@see getGatewayGotchas()}.
     *
     * Behaviours proven against the live gateway APIs that reflection cannot reveal —
     * required fields, header quirks, account entitlements, idempotency rules,
     * embedded-vs-redirect return shapes, and browser-SDK timing. Keyed by gateway value,
     * plus a `general` bucket that applies across gateways.
     *
     * @var array<string, list<array{title: string, detail: string}>>
     */
    private const GATEWAY_GOTCHAS = [
        'general' => [
            [
                'title' => 'createCheckoutSession returns different fields per gateway',
                'detail' => 'Hosted-redirect gateways (Paymob, Fawry, PayPal, PayTabs, PayLink) populate CheckoutSession::redirectUrl — send the guest there. CyberSource UC returns jwt (the capture context) + clientLibrary for its embedded widget. Airwallex returns jwt (the PaymentIntent client_secret) + reference (the intent id) for its embedded Drop-in and leaves redirectUrl null. Read the field your gateway populates, not redirectUrl unconditionally.',
            ],
            [
                'title' => 'Webhooks may arrive as GET or POST',
                'detail' => 'Some gateways deliver their server callback as a GET redirect rather than a POST (e.g. PayTabs/Fawry return callbacks). Route both methods to verifyWebhook; a GET carries no body, so signature verification simply fails closed.',
            ],
        ],
        'cybersource_uc' => [
            [
                'title' => 'Accept header must be application/hal+json',
                'detail' => 'CyberSource\'s edge returns 404 "Resource not found" for /pts/v2/payments and /risk/v1/* when Accept is application/json — only the Unified Checkout session endpoints tolerate it, so the 404 masquerades as an unprovisioned-merchant error. The SDK sends application/hal+json on every request (SignsCybersourceRequests), but anyone porting the flow elsewhere must too.',
            ],
            [
                'title' => 'Two flows — manual transient token vs orchestrated',
                'detail' => 'Manual: the widget returns a transient token; charge() it server-side via /pts/v2/payments (needs the Payments API product on the account). Orchestrated: set CheckoutSessionRequest::completeMandate so the widget runs the whole payment and returns a signed result JWT verified offline by confirmOrchestratedPayment() (needs the autoProcessing entitlement). A 404 on /pts/v2/payments while the capture-context call succeeds usually means the account lacks the Payments API product.',
            ],
            [
                'title' => 'enable3ds/captureMandate shape only the orchestrated capture context; allowedCardNetworks is a typed enum',
                'detail' => 'Two capture-context knobs apply to the ORCHESTRATED flow only (a completeMandate on CheckoutSessionRequest), not the manual transient-token flow: enable3ds is emitted as completeMandate.consumerAuthentication (whether the widget runs 3-D Secure) and decisionManager as completeMandate.decisionManager (device fingerprinting). Both are ignored without a completeMandate — in the manual flow 3DS is instead a separate enrollPayerAuth/validatePayerAuth handshake, so setting enable3ds there does nothing. The widget\'s captureMandate (which billing/contact fields it collects: billingType FULL/PARTIAL/NONE, requestEmail, requestPhone, requestShipping, showAcceptedNetworkIcons) is built from a CybersourceCheckoutOptions passed on CheckoutSessionRequest::options; its defaults reproduce the SDK\'s historical fixed mandate (FULL billing + email requested), so omitting options changes nothing. allowedCardNetworks is a typed CybersourceCardNetwork enum (Visa, Mastercard, Amex, Carnet, CartesBancaires, Cup, DinersClub, Discover, Eftpos, Elo, Jaywan, Jcb, Jcrew, Kcp, Mada, Maestro, Meeza, Paypak, Uatp), not free-form strings — map any config strings with CybersourceCardNetwork::tryFrom().',
            ],
            [
                'title' => 'Flex Microform is a third front-end option — createMicroformSession()',
                'detail' => 'Besides the full Unified Checkout widget, CyberSource offers Flex Microform: a low-level, PCI-friendly card-field tokenizer you style yourself. createMicroformSession() — a CyberSource-specific method (like confirmOrchestratedPayment, outside PaymentGatewayInterface) — posts to /microform/v2/sessions and returns a capture-context JWT plus session->clientLibrary (the Microform.js URL); load it, and the browser mints a transient token you feed straight into charge()/enrollPayerAuth()/vaultInstrument(), the same path the widget uses. Gotchas: the Microform context carries no order amount or capture mandate (the amount is applied at charge, not at session creation); targetOrigins must exactly match the launching page origin (scheme+host+port, no wildcards, subdomains listed explicitly) or Microform silently fails to load; at least one allowedCardNetworks entry is required for cards (the SDK fixes allowedPaymentTypes to CARD); and the capture-context JWT is short-lived, so mint it per checkout rather than ahead of time. An optional billTo billing address is sent as orderInformation.billTo when the CheckoutSessionRequest supplies one (for AVS and risk screening); it is omitted otherwise. Needs the Microform/Flex entitlement on the account.',
            ],
            [
                'title' => 'Flex Microform.js v2 browser flow — new Flex → microform(\'card\') → createField → createToken',
                'detail' => 'Browser side of createMicroformSession(): load the library from the capture context\'s ctx[0].data.clientLibrary (surfaced as CheckoutSession::clientLibrary, with clientLibraryIntegrity for the SRI hash + crossorigin=anonymous) — it exposes a global Flex. Init is new Flex(captureContext) then flex.microform(\'card\', { styles }); pass the literal \'card\' string in v2, not just the options object. Microform hosts only two fields — createField(\'number\', { placeholder }).load(\'#sel\') and createField(\'securityCode\').load(\'#sel\'), each an iframe sized to its container — so the target element must be in the DOM and visible (not display:none / zero-size) before .load() or the field renders unusably; clear the container before re-loading when you mint a fresh context per attempt. Expiry is not a hosted field: collect it yourself and call createToken({ expirationMonth: \'MM\', expirationYear: \'YYYY\' }, cb) with a 2-digit month and 4-digit year.',
            ],
            [
                'title' => 'Microform createToken returns a bare transient-token JWT — never JSON.stringify it',
                'detail' => 'createToken(options, cb)\'s callback second argument is the transient token as a bare, dot-delimited JWT string (header.payload.signature) — the same value the Unified Checkout widget yields. POST it verbatim and set it as ChargeRequest::transientToken (or hand it to enrollPayerAuth()/vaultInstrument()). Do not JSON.stringify it first: the SDK decodes the token by splitting on ".", so a JSON-quoted string has no segments and decoding fails. CyberSource\'s own sample calls JSON.stringify(token) only to carry it across a two-page form post, then does transientTokenJwt = JSON.parse(...) back to the raw string before charging; posting straight from fetch, skip both and send the raw string.',
            ],
            [
                'title' => '3-D Secure enforces a fully-authenticated ECI — read eciRaw, listen for PayerAuthenticationEciRejected',
                'detail' => 'A completed payer authentication (a frictionless enrollPayerAuth, or validatePayerAuth after a challenge) is only treated as successful when its ECI is fully authenticated: the driver sets PayerAuthResult::success=false and dispatches a PayerAuthenticationEciRejected event when the resolved ECI is not 02 or 05. Key detail: the decision reads the network-normalised eciRaw (02 = Mastercard success, 05 = Visa/Amex/JCB/Diners/Discover success), NOT the eci field — Mastercard success is 02 in eciRaw while the eci field only ever holds 05/06/07 (Mastercard uses ucafCollectionIndicator), so checking eci alone silently rejects every authenticated Mastercard. Attempted (01/06) and not-authenticated (00/07) ECIs are rejected; a pending step-up challenge (no final ECI yet) and a response with no ECI at all are left untouched. The event fires only when the driver has an EventDispatcher: PaymentGatewayFactory wires one, but if you construct CybersourceUnifiedCheckoutGateway directly, pass one as the optional third constructor argument. Use the CybersourceEci value object (fromConsumerAuthentication/fromRaw, isFullyAuthenticated, outcome) to classify an ECI yourself.',
            ],
            [
                'title' => 'Orchestrated result JWT envelope varies',
                'detail' => 'The completed-payment result JWT nests its claims under a details, data, or content envelope (or flat at the top level) across Unified Checkout client versions. VerifiesResultJwt resolves each claim across all of them — do not assume a single envelope.',
            ],
            [
                'title' => 'Idempotency is short-lived — reconcile before a delayed retry',
                'detail' => 'The v-c-idempotency-id header (sent by CybersourceClient on every write) only deduplicates within CyberSource\'s bounded retention window, so a retry spaced hours or days after the first attempt — a scheduled installment or a subscription rebill — is not caught by the key. Before such a retry, reconcile via Transaction Search: findSuccessfulTransactionByReference() (POST /tss/v2/searches, querying clientReferenceInformation.code) returns any prior AUTHORIZED/CAPTURED charge on the same reference so you adopt it instead of charging twice. TSS is eventually consistent (a lag of seconds to minutes), so it is reliable a day later but not for an immediate retry — send a stable per-attempt reference on the original charge so it stays findable.',
            ],
            [
                'title' => 'PARTIAL_AUTHORIZED holds funds — reverse it, never treat it as paid',
                'detail' => 'A low-balance or prepaid card can approve less than the requested amount (status PARTIAL_AUTHORIZED). It holds funds but does not settle the charge, and the SDK\'s normalized PaymentStatus flattens it to Authorized — so a naive success check strands a hold on the cardholder\'s card. Detect the raw status with CybersourceTransactionStatus::isPartialAuthorization(), call reverseAuthorization() to release the hold, then treat the charge as declined.',
            ],
            [
                'title' => 'Classify a decline before retrying (reason + merchantAdvice.code)',
                'detail' => 'Retrying a permanently-declined credential (expired/stolen/invalid account; Mastercard merchant-advice codes 01/03/04/21/99, Visa 1 = ISSUER_WILL_NEVER_APPROVE) never succeeds and can draw processor penalties. Read both errorInformation.reason and processorInformation.merchantAdvice.code — DeclineClassifier::classify()/fromResult() does this and returns a DeclineOutcome flagging isPermanent vs isRetryable(), plus customerMessage() for safe cardholder-facing copy. Retry only transient declines (insufficient funds, issuer unavailable), and no more than 15 times over 30 days per the card-network mandates. fromResult() takes a SubscriptionResult as well as a PaymentResult, so a Recurring Billing create that returns requestStatus DECLINED is triaged by the same rules; and classify() reads a raw array, so a failed rebill arriving on a verified webhook (the event that turns a subscription DELINQUENT) is classified straight from WebhookEvent::\$payload.',
            ],
            [
                'title' => 'Review and pending states are neither paid nor failed',
                'detail' => 'AUTHORIZED_PENDING_REVIEW, PENDING_REVIEW, and PENDING_AUTHENTICATION are held for a Decision Manager review or payer authentication — not a settlement and not a terminal decline. Do not mark the order paid or failed; use CybersourceTransactionStatus::isReviewOrIncomplete() to detect them, then poll getTransaction() or await the webhook. The normalized PaymentStatus reads AUTHORIZED_PENDING_REVIEW as Authorized and the pending states as Pending, so the raw status is what distinguishes a held transaction from a settled one.',
            ],
            [
                'title' => 'Merchant-initiated charges need the original cardholder transaction id',
                'detail' => 'A recurring or installment charge via chargeStoredCredential() is a merchant-initiated transaction (MIT) that must reference the network transaction id of the initial cardholder-initiated charge (the CIT that established the credential-on-file). The first charge in the series is that CIT — capture its transaction id and thread it into the subsequent StoredCredentialChargeRequests. Omitting it leads issuers to decline or downgrade the MIT.',
            ],
            [
                'title' => 'Recurring Billing subscriptions bill on CyberSource\'s schedule — createSubscription() and its lifecycle',
                'detail' => 'For a series CyberSource runs itself, createSubscription() posts to /rbs/v1/subscriptions and enrols an existing TMS customer token on a billing schedule; getSubscription/listSubscriptions/updateSubscription/cancelSubscription/suspendSubscription/activateSubscription drive it afterwards (listSubscriptions is CyberSource\'s getAllSubscriptions, updateSubscription its PATCH). These are CyberSource-specific methods (like confirmOrchestratedPayment and createMicroformSession) outside PaymentGatewayInterface, so call them on the concrete driver — the LoggingGateway/EventDispatchingGateway decorators only proxy the interface. Gotchas: the vault customer must already exist (vaultInstrument() first, or an orchestrated checkout with createToken), since the subscription references paymentInformation.customer.id and never carries card data; nothing is charged at create time — the first charge falls on startDate, which must be a UTC timestamp (the SDK expands a bare YYYY-MM-DD to midnight UTC); the cadence comes from a planId, from inline billingPeriod/billingCycles, or both with the inline values winning; and cancel is terminal while suspend is reversible via activateSubscription(). Needs the Recurring Billing entitlement on the account. Contrast chargeStoredCredential(), where your own scheduler raises each charge. A create CyberSource refuses returns success=false with requestStatus DECLINED — pass the SubscriptionResult to DeclineClassifier::fromResult() to tell a permanent refusal (re-collect the card) from a transient one (retry) instead of guessing.',
            ],
            [
                'title' => 'A subscription response has two statuses — read subscriptionInformation.status, not the top-level one',
                'detail' => 'RBS lifecycle responses carry a top-level status that reports only whether the CALL was accepted (COMPLETED on create/activate, ACCEPTED on cancel/suspend, DECLINED on refusal) and a separate subscriptionInformation.status holding the subscription\'s own state (PENDING/ACTIVE/SUSPENDED/DELINQUENT/CANCELLED/COMPLETED/FAILED). A successful create commonly returns COMPLETED + PENDING, because the subscription has not reached its first billing date yet — treating the top-level status as the subscription state reads that as finished. SubscriptionResult keeps them apart: ->status is the normalized SubscriptionStatus (isBilling()/isTerminal()) and ->requestStatus is the raw call verdict. A getSubscription() lookup returns no top-level status at all, so ->requestStatus is null there.',
            ],
            [
                'title' => 'updateSubscription cannot change the cadence or the currency — and can come back PENDING_REVIEW',
                'detail' => 'The PATCH /rbs/v1/subscriptions/{id} schema is deliberately narrower than create: planInformation accepts billingCycles but NOT billingPeriod, and orderInformation.amountDetails accepts billingAmount/setupFee but NOT currency. So a live subscription can be re-priced (in the currency it was created with) and have its total cycle count changed, but switching monthly to annual means cancelling and re-creating. It also cannot be re-pointed at a different customer token — to move it onto another card, update the payment instrument behind the existing TMS customer instead. processingInformation is ignored on an update, so the SDK does not send it. UpdateSubscriptionRequest is a partial update: only the fields you set are emitted, and a Money you pass contributes its amount while its currency is ignored. Watch the response: an update returns COMPLETED, PENDING_REVIEW, DECLINED, or INVALID_REQUEST — PENDING_REVIEW means accepted but held for review, not applied, and the SDK reports it as success=true (like every other pending state) with requestStatus preserved so you can tell it apart.',
            ],
            [
                'title' => 'listSubscriptions is paged — read hasMore(), do not assume one call returns everything',
                'detail' => 'listSubscriptions() (CyberSource getAllSubscriptions, GET /rbs/v1/subscriptions) returns a SubscriptionPage, not a bare array: the page\'s records plus totalCount for the whole filtered set and the offset/limit window they came from. CyberSource defaults limit to 20 and caps it at 100, so a merchant with hundreds of subscriptions silently gets only the first 20 if you ignore paging — walk it with $page->hasMore() and $request->nextPage(), which carries every filter forward. Filters (status, code, customerId, orderReference→clientReferenceInformationCode) are optional and combine; the status filter takes a normalized SubscriptionStatus and is mapped back to CyberSource\'s own spelling. The query string is part of the SIGNED request target, so it must be built once and sent verbatim — the SDK does this. Filtering by SubscriptionStatus::Delinquent is the practical way to find subscriptions whose last rebill failed and need dunning; pair it with DeclineClassifier on the failed payment.',
            ],
            [
                'title' => 'Reactivation\'s processMissedPayments is honoured only under one merchant setting',
                'detail' => 'activateSubscription($id, processMissedPayments:) is sent as a query parameter (and is part of the signed request target). CyberSource applies it only when the account\'s reactivation setting is "Ask each time before reactivating" — under every other setting the value is silently ignored and the configured behaviour wins, so do not rely on it to suppress a catch-up bill. Only a SUSPENDED subscription can be reactivated (never a cancelled or completed one), and getSubscription() returns a reactivationInformation block (surfaced on SubscriptionResult::$raw) telling you how many payments were missed and what they total — read it before reactivating.',
            ],
            [
                'title' => 'Reporting is asynchronous and downloads are keyed by name+date, not report id',
                'detail' => 'createReport() queues an ad-hoc report and CyberSource answers with an EMPTY 201 — there is no report id in the response, so the SDK returns bool. Find the queued report with listReports() (the search window is required; timeQueryType picks whether it filters on executedTime or reportTimeFrame, which differ for any report generated after the data it covers), then read reportId and status off the match. Generation is async: a report existing does not mean a file exists, so check ReportStatus::isReady() first — isInProgress() (PENDING/QUEUED/RUNNING) means poll, NO_DATA is a SUCCESSFUL run that matched nothing (finished, but no file), and ERROR is the only real failure. downloadReport() is keyed by report NAME and DATE, never by report id, and the date is the END of the period covered in the report\'s own timezone — a report running midnight-to-midnight on the 9th downloads under the 10th. That off-by-one is the usual cause of a 404 on a report that plainly exists; Report::downloadRequest() derives the name, date, and format correctly from a listed report, so prefer it to building one by hand.',
            ],
            [
                'title' => 'A report download returns a file, not JSON — the Accept header must match the format',
                'detail' => 'GET /reporting/v3/report-downloads returns the report body itself (CSV or XML), so the SDK sends the requested ReportFormat as the Accept header instead of the hal+json it uses everywhere else, and reads the raw body via CybersourceClient::getBody(). Ask for a format the report was NOT generated in and the call fails — CyberSource does not convert — so pass the format from the listed report rather than assuming CSV. The result is a ReportFile carrying the bytes verbatim (isEmpty()/bytes()/filename()); it is deliberately not parsed, because a report\'s columns depend on its definition and the reportFields requested. Reporting calls are scoped by organizationId, resolved from the request, else the `organization_id` credential in the extra bag, else the merchant id — set organization_id for a portfolio or partner account whose reports live under a different organization.',
            ],
            [
                'title' => 'Report subscriptions are PUT-keyed by name — creating one twice replaces it',
                'detail' => 'createReportSubscription() is a PUT on /reporting/v3/report-subscriptions keyed by reportName, so creating a subscription under a name that already exists OVERWRITES that schedule instead of erroring — treat it as create-or-replace and read the stored schedule back with getReportSubscription(). startTime is a clock time of day as hhmm (e.g. "0200"), not a date. Weekly and monthly cadences also need startDay (1-7 for weekly, 1 is Sunday; 1-31 for monthly) and a USER_DEFINED cadence needs an ISO 8601 reportInterval such as PT2H30M; the SDK emits each only for the cadence that uses it (ReportFrequency::needsStartDay()/needsInterval()), so a daily subscription does not carry fields CyberSource would reject. deleteReportSubscription() stops future runs only — reports the schedule already produced stay downloadable. ADHOC is not schedulable: it is the frequency a one-off report reports back as.',
            ],
            [
                'title' => 'Visa Bank Account Validation: only resultCode 00 passes, and -1/-2 mean retry, not reject',
                'detail' => 'validateBankAccount() posts to /bavs/v1/account-validations to check a routing/account pair is a real open account BEFORE an ACH debit, which is how Nacha\'s account-validation mandate for WEB debits is met. It authorises nothing and moves no money. Two codes come back and they answer different questions: resultCode is the verdict on the account (documented values 00, 04, 98, 99 — only 00 is a pass, so BankAccountValidationResult::isValid() treats every other value as not-validated rather than guessing), while rawValidationCode says whether the check could run at all (-1 unknown error, -2 service unavailable, 12-16 actual validation results). isInconclusive() flags the -1/-2 case: that is NOT evidence of a bad account, so retry it instead of rejecting the customer\'s bank details. Pass either the raw bank block or a vaulted customer/paymentInstrument/instrumentIdentifier token — with a token the bank block becomes optional and the raw numbers never leave the vault. The SDK omits a half-supplied bank block (routing without account, or vice versa) rather than sending one the service rejects. Routing and account numbers are sensitive: this operation sits outside PaymentGatewayInterface, so the LoggingGateway decorator never sees or logs them. Needs the BAVS entitlement.',
            ],
            [
                'title' => 'Vaulted cards have a full lifecycle — read state at rest, re-date instead of re-collecting',
                'detail' => 'vaultInstrument() only CREATES tokens; the lifecycle is separate. getPaymentInstrument()/listPaymentInstruments() read the stored record back (expiry, masked number, PaymentInstrumentState ACTIVE|CLOSED, default flag) so a dead card is caught at rest rather than at charge time — PaymentInstrument::isExpired() and state->isChargeable() answer that without a network call, and PaymentInstrumentPage::default() finds the instrument payments fall back to (paged, 20 default / 100 max, walk with hasMore()). updatePaymentInstrument() is a PATCH partial update and is the fix for a REISSUED card: re-dating the stored expiry keeps every subscription and stored-credential charge already pointing at that instrument working, with no re-collection. The card NUMBER is not updatable — it belongs to the instrument identifier behind the instrument — so a genuinely new card is vaulted afresh. deletePaymentInstrument() also deletes the instrument identifier when no other instrument references that card; a customer\'s DEFAULT instrument cannot be deleted while they hold others, so promote another first with UpdatePaymentInstrumentRequest::makeDefault. deleteCustomer() removes the customer and every instrument under them — cancel their subscriptions first or the next rebill fails.',
            ],
            [
                'title' => 'Plans are the template subscriptions are built from — and unlike a subscription, a plan CAN change cadence',
                'detail' => 'createPlan()/getPlan()/listPlans()/updatePlan()/activatePlan()/deactivatePlan()/deletePlan() drive /rbs/v1/plans, the reusable pricing CreateSubscriptionRequest::planId points at. Without them plans must be built by hand in the Business Center. Key asymmetry: updatePlan() CAN change billingPeriod (a plan is a template, nothing is billing against it) whereas updateSubscription() cannot (a live agreement). A plan change governs subscriptions created afterwards; it does not retroactively re-price those already running. PlanStatus is DRAFT|ACTIVE|INACTIVE and only ACTIVE is subscribable (PlanResult::isSubscribable()) — create as Draft to stage. deactivatePlan() closes a plan to NEW sign-ups without cancelling existing subscriptions; deletePlan() only works when nothing depends on it. generatePlanCode() asks CyberSource to allocate a code. Plan responses carry the same two statuses as subscriptions: planInformation.status vs the top-level request verdict.',
            ],
            [
                'title' => 'Account Updater is the standing fix for recurring-billing churn — submit tokens, poll, reconcile',
                'detail' => 'createAccountUpdaterBatch() posts TMS token ids (never card numbers) to /accountupdater/v1/batches and the card networks report back which stored cards were reissued, re-dated, or closed, updating the vault. Without it a reissued card fails EVERY scheduled charge permanently and you only learn of it from the decline — DeclineClassifier will correctly flag it permanent, but by then the rebill has already failed. Processing is asynchronous (hours to days), so the create returns a batch id: poll getAccountUpdaterBatchStatus() until AccountUpdaterBatchStatus::isComplete() (isInProgress() = RECEIVED/VALIDATED/PROCESSING, isFailed() = REJECTED/DECLINED), check hasUpdates() before bothering with getAccountUpdaterBatchReport(), then reconcile the per-card changes against your own copies and retire closed accounts instead of retrying them. Amex is a separate flow: those cards must go in an AccountUpdaterBatchType::AmexRegistration batch, not the oneOff batch Visa/Mastercard use. Needs the Account Updater entitlement, which may also need enabling at the portfolio level.',
            ],
            [
                'title' => 'Guard concurrent scheduled charges with an atomic claim',
                'detail' => 'When a scheduler and a manual run (or two app servers) can both fire the same due charge, claim the work with a database compare-and-swap before calling the gateway and never hold a lock across the network round-trip. Combine the claim with the v-c-idempotency-id key and a findSuccessfulTransactionByReference() reconcile for exactly-once charging. The SDK provides the gateway primitives; the claim and the scheduling live in your app.',
            ],
        ],
        'paymob' => [
            [
                'title' => 'A card iframe id is required for a redirect URL',
                'detail' => 'createCheckoutSession runs the older auth -> register order -> payment key -> iframe flow and needs BOTH extra.integrations.card (the payment integration id) AND extra.iframes.card (the iframe id). With no iframe id, CheckoutSession::redirectUrl is null and the caller must drive the payment token itself. Iframe ids are separate from integration/payment-method ids and are not part of the newer Intention API, so an Intention-API-only setup will not have one.',
            ],
        ],
        'airwallex' => [
            [
                'title' => 'The account must be activated for card acceptance',
                'detail' => 'An account not provisioned for Online Payments returns HTTP 400 {"code":"configuration_error","message":"Invalid request against merchant configuration. Please contact your account manager."} for every payment-intent create, in every currency, on an otherwise-valid request. Login still succeeds — only intent creation fails. This is an account-setup step, not a code or payload issue.',
            ],
            [
                'title' => 'descriptor is capped at 32 characters',
                'detail' => 'CheckoutSessionRequest::description maps to the intent descriptor, which Airwallex rejects (HTTP 400 "descriptor should be no longer than 32 characters") beyond 32 chars. Pass a short statement descriptor, not a long human-readable order description.',
            ],
            [
                'title' => 'request_id must be unique per attempt',
                'detail' => 'The SDK derives the intent request_id (an idempotency key) from orderReference. Reusing the same orderReference across retries fails with HTTP 400 "The request ID ... has been used previously." Make orderReference unique per attempt (e.g. append the local payment/attempt id); the base reference can live on merchant_order_id for reconciliation.',
            ],
            [
                'title' => 'Scoped org-level keys need the account id (x-login-as)',
                'detail' => 'A key scoped at the organisation with access to a specific account needs extra.account_id, which the SDK sends as x-login-as to target it. A key issued directly at the account level does not — leave account_id blank, since sending x-login-as with the wrong/own account can 401.',
            ],
            [
                'title' => 'Embedded flow returns a client_secret, not a redirect',
                'detail' => 'createCheckoutSession creates a PaymentIntent and returns the client_secret (in CheckoutSession::jwt) + intent id (in ::reference) for the Airwallex JS Drop-in; redirectUrl (next_action.url) is null for cards. Confirm client-side, then verify the outcome server-side with getTransaction(intentId) — never trust the browser success claim. Host/env: api-demo.airwallex.com (env "demo") for test, api.airwallex.com (env "prod") for live.',
            ],
        ],
        'authorize_net' => [
            [
                'title' => 'No createCheckoutSession — Accept.js opaque-data flow',
                'detail' => 'Authorize.Net has no hosted-redirect session. Tokenise the card client-side with Accept.js into a one-time opaque-data nonce, then charge() it with the nonce as ChargeRequest::transientToken (the SDK wraps it as opaqueData COMMON.ACCEPT.INAPP.PAYMENT). The browser form needs the public client key + API login id; Accept.js loads from jstest.authorize.net in sandbox, js.authorize.net in live.',
            ],
            [
                'title' => 'Accept.js loads AcceptCore.js asynchronously',
                'detail' => 'The Accept.js script is a bootstrap that async-loads AcceptCore.js, which defines dispatchData. Calling Accept.dispatchData immediately after the script onload throws "dispatchData is not a function". Load Accept.js statically in the page <head> so it is ready before submit, or poll for typeof Accept.dispatchData === "function".',
            ],
        ],
        'fawry' => [
            [
                'title' => 'Hosted checkout returns the URL as the raw body',
                'detail' => 'The hosted-checkout init responds with the redirect URL as the raw response body (plain text), not a JSON field. CheckoutSession::redirectUrl carries it.',
            ],
        ],
        'paytabs' => [
            [
                'title' => 'Callback signature scheme',
                'detail' => 'IPN/return callbacks verify as hmac_sha256(http_build_query(ksort(array_filter(fields without signature))), server_key) in lowercase hex, over the flat posted fields (respStatus, tranRef, cartId, ...). Callbacks may arrive via GET or POST.',
            ],
        ],
        'paypal' => [
            [
                'title' => 'OAuth then create-order; the approval link is the redirect',
                'detail' => 'createCheckoutSession first exchanges client id/secret for a bearer token (POST /v1/oauth2/token) then creates an order (POST /v2/checkout/orders). The redirect URL is the order link with rel "payer-action"/"approve". Test host api-m.sandbox.paypal.com, live api-m.paypal.com.',
            ],
        ],
        'tamara' => [
            [
                'title' => 'Redirect BNPL flow — no immediate charge, authorise before capture',
                'detail' => 'Tamara has no charge(). createCheckoutSession returns the hosted checkout_url (CheckoutSession::redirectUrl) and the Tamara order_id (::reference). After the customer approves, Tamara sends an order_approved webhook; call the Tamara-specific authorise($orderId) to move the order to "authorised", then capture() on fulfilment. Capturing before authorising is rejected.',
            ],
            [
                'title' => 'void and reverseAuthorization both map to cancel',
                'detail' => 'Tamara exposes one cancel operation (POST /orders/{id}/cancel) for undoing an authorised order before capture, so both void() and reverseAuthorization() call it. Cancel requires the order total, so void() first fetches the order (GET /orders/{id}) to echo its total_amount back; reverseAuthorization() uses the amount on the request. refund() (POST /payments/simplified-refund/{id}) applies after capture.',
            ],
            [
                'title' => 'Bearer token auth; webhooks verify a shared Authorization header',
                'detail' => 'Every request is authenticated with the merchant API/merchant token as an Authorization: Bearer header (config shared_secret). Tamara does not sign webhooks with an HMAC/JWT; instead it echoes the Authorization header value you registered for the webhook URL, so verifyWebhook() compares the inbound Authorization header against webhook_secret in constant time. Hosts: api-sandbox.tamara.co (test), api.tamara.co (live). Amounts are JSON numbers in major units ({amount, currency}).',
            ],
        ],
    ];

    private PackageReflector $reflector;

    public function __construct()
    {
        $this->reflector = new PackageReflector;
    }

    /**
     * Get an orientation guide for the hyprpay/payments SDK: what it is, how to install
     * and configure it, the gateway roster, and the other tools this MCP exposes. Call
     * this first when starting any integration task against the package.
     */
    #[McpTool(name: 'get_sdk_overview')]
    public function getSdkOverview(): string
    {
        $root = $this->reflector->packageRoot();
        $composer = $this->readJson($root.'/composer.json');
        $readme = @file_get_contents($root.'/README.md');

        $gateways = [];
        foreach (GatewayName::cases() as $case) {
            $gateways[] = sprintf('- **%s** — `GatewayName::%s` (`%s`)', $case->label(), $case->name, $case->value);
        }

        $tools = [
            '- `get_sdk_overview` — this guide.',
            '- `list_gateways` — every gateway and the operations each one supports.',
            '- `get_operation_details` — an operation\'s request DTO, return type, and supporting gateways.',
            '- `get_class_details` — full reflection of any class, interface, enum, or trait in the SDK.',
            '- `get_code_template` — a ready-to-adapt PHP snippet for a gateway + operation.',
            '- `search` — find types by name or purpose across the package.',
            '- `get_gateway_gotchas` — real-world integration pitfalls per gateway (header quirks, account entitlements, required fields, idempotency, embedded-vs-redirect, browser-SDK timing) that reflection cannot show.',
        ];

        $header = sprintf(
            "# %s\n\n%s\n\n**Version:** %s  \n**Install:** `composer require %s`\n",
            $composer['name'] ?? 'hyprpay/payments',
            $composer['description'] ?? '',
            $composer['version'] ?? 'dev',
            $composer['name'] ?? 'hyprpay/payments',
        );

        return implode("\n", [
            $header,
            "## Gateways\n",
            implode("\n", $gateways),
            "\n## MCP tools in this server\n",
            implode("\n", $tools),
            "\n## Package README\n",
            is_string($readme) ? $readme : '(README unavailable)',
        ]);
    }

    /**
     * List every payment gateway the SDK can drive, with its GatewayName key, concrete
     * driver class, one-line purpose, and the exact set of operations it supports (some
     * gateways implement only a subset). Use this to pick a gateway for a task.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_gateways')]
    public function listGateways(): array
    {
        return [
            'operations' => $this->reflector->operations(),
            'gateways' => $this->reflector->gatewayRoster(),
            'note' => 'supported_operations lists only what each driver implements; every other operation throws UnsupportedOperationException.',
        ];
    }

    /**
     * Describe a single payment operation from PaymentGatewayInterface: its signature,
     * the request DTO it takes (fully reflected), the result DTO it returns, and which
     * gateways support it. Pass the method name, e.g. "charge", "refund", "createCheckoutSession".
     *
     * @param  string  $operation  The interface method name, e.g. "charge", "capture", "refund", "verifyWebhook".
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_operation_details')]
    public function getOperationDetails(string $operation): array
    {
        if (! in_array($operation, $this->reflector->operations(), true)) {
            return [
                'error' => 'unknown_operation',
                'operation' => $operation,
                'available_operations' => $this->reflector->operations(),
            ];
        }

        $details = $this->reflector->describeOperation($operation);
        $method = new ReflectionMethod(PaymentGatewayInterface::class, $operation);

        $related = [];
        foreach ($method->getParameters() as $parameter) {
            $fqcn = $this->hyprpayTypeName($parameter->getType());
            if ($fqcn !== null) {
                $related[$parameter->getName()] = $this->reflector->describeType($fqcn);
            }
        }

        $returnFqcn = $this->hyprpayTypeName($method->getReturnType());
        if ($returnFqcn !== null) {
            $details['returns']['shape'] = $this->reflector->describeType($returnFqcn);
        }

        $supporting = [];
        foreach ($this->reflector->gatewayRoster() as $gateway) {
            if (in_array($operation, $gateway['supported_operations'], true)) {
                $supporting[] = $gateway['key'];
            }
        }

        $details['request_dtos'] = $related;
        $details['supported_by'] = $supporting;
        $details['money_moving'] = in_array($operation, self::MONEY_MOVING, true);

        return $details;
    }

    /**
     * Reflect any type in the SDK — class, interface, enum, or trait — returning its kind,
     * docblock, constructor parameters, public properties, and methods. Accepts a short
     * name ("ChargeRequest", "Money", "PaymentStatus") or a fully-qualified name.
     *
     * @param  string  $name  The type to describe: short name or fully-qualified class name.
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_class_details')]
    public function getClassDetails(string $name): array
    {
        $matches = $this->reflector->findTypes($name);

        if ($matches === []) {
            return [
                'error' => 'type_not_found',
                'query' => $name,
                'hint' => 'Use the search tool to find a type by keyword, or list_gateways for drivers.',
            ];
        }

        if (count($matches) > 1) {
            return [
                'error' => 'ambiguous_type',
                'query' => $name,
                'candidates' => $matches,
                'hint' => 'Re-run get_class_details with one of the fully-qualified candidates.',
            ];
        }

        return $this->reflector->describeType($matches[0]);
    }

    /**
     * Generate a ready-to-adapt PHP code snippet that performs one operation through one
     * gateway, built from the real request-DTO constructor. Returns an error listing the
     * supporting gateways when the chosen gateway does not implement the operation.
     *
     * @param  string  $gateway  Gateway key or enum case, e.g. "cybersource_uc" or "CybersourceUnifiedCheckout".
     * @param  string  $operation  The operation to perform, e.g. "charge", "refund", "createCheckoutSession".
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_code_template')]
    public function getCodeTemplate(string $gateway, string $operation): array
    {
        $case = $this->resolveGateway($gateway);

        if ($case === null) {
            return [
                'error' => 'unknown_gateway',
                'gateway' => $gateway,
                'available_gateways' => array_map(static fn (GatewayName $g): string => $g->value, GatewayName::cases()),
            ];
        }

        if (! in_array($operation, $this->reflector->operations(), true)) {
            return [
                'error' => 'unknown_operation',
                'operation' => $operation,
                'available_operations' => $this->reflector->operations(),
            ];
        }

        $driver = $this->reflector->gatewayClassMap()[$case->name];

        if (! in_array($operation, $this->reflector->supportedOperations($driver), true)) {
            $supporting = [];
            foreach ($this->reflector->gatewayRoster() as $entry) {
                if (in_array($operation, $entry['supported_operations'], true)) {
                    $supporting[] = $entry['key'];
                }
            }

            return [
                'error' => 'operation_unsupported_by_gateway',
                'gateway' => $case->value,
                'operation' => $operation,
                'supported_by' => $supporting,
            ];
        }

        return [
            'gateway' => $case->value,
            'operation' => $operation,
            'money_moving' => in_array($operation, self::MONEY_MOVING, true),
            'code' => $this->renderTemplate($case, $operation),
            'note' => in_array($operation, self::MONEY_MOVING, true)
                ? 'This operation moves money. Resolve credentials in test mode and require human confirmation before running it autonomously.'
                : 'Credentials are resolved by the factory/CredentialResolver — never pass secrets as arguments.',
        ];
    }

    /**
     * Search the whole package for types whose name or one-line purpose matches a keyword.
     * Returns matching classes, interfaces, enums, and traits with their summaries. Use it
     * to locate the right DTO, gateway, enum, or value object when you only know a concept.
     *
     * @param  string  $query  A keyword to match against type names and their docblock summaries.
     * @return array<string, mixed>
     */
    #[McpTool(name: 'search')]
    public function search(string $query): array
    {
        $needle = strtolower(trim($query));

        if ($needle === '') {
            return ['error' => 'empty_query'];
        }

        $results = [];

        foreach ($this->reflector->allTypes() as $fqcn) {
            $short = substr((string) strrchr($fqcn, '\\'), 1);
            $summary = $this->reflector->summaryOf($fqcn);

            if (str_contains(strtolower($short), $needle) || str_contains(strtolower($summary), $needle)) {
                $results[] = [
                    'name' => $fqcn,
                    'short_name' => $short,
                    'summary' => $summary,
                ];
            }

            if (count($results) >= 60) {
                break;
            }
        }

        return [
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ];
    }

    /**
     * Real-world integration gotchas for the gateways: behaviours proven against the live
     * gateway APIs that reflection and DTOs never reveal — required fields, header quirks,
     * account entitlements, idempotency rules, embedded-vs-redirect return shapes, and
     * browser-SDK timing. Pass a gateway key for its notes, or omit for every gateway plus
     * the general notes. Consult this before wiring a gateway end to end, and when a live
     * call returns a 400/404/401 that the DTOs do not explain.
     *
     * @param  string  $gateway  Gateway key or enum case (e.g. "airwallex", "cybersource_uc"), or "" for all.
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_gateway_gotchas')]
    public function getGatewayGotchas(string $gateway = ''): array
    {
        $gateway = trim($gateway);

        if ($gateway === '') {
            return [
                'general' => self::GATEWAY_GOTCHAS['general'],
                'gateways' => array_diff_key(self::GATEWAY_GOTCHAS, ['general' => null]),
                'note' => 'Operational pitfalls from real integrations, not reflected from code. Pass a gateway key to focus.',
            ];
        }

        $case = $this->resolveGateway($gateway);
        $key = $case?->value ?? strtolower($gateway);

        if ($key === 'general' || ! isset(self::GATEWAY_GOTCHAS[$key])) {
            return [
                'error' => 'no_gotchas_for_gateway',
                'gateway' => $gateway,
                'available' => array_values(array_diff(array_keys(self::GATEWAY_GOTCHAS), ['general'])),
            ];
        }

        return [
            'gateway' => $key,
            'gotchas' => self::GATEWAY_GOTCHAS[$key],
            'general' => self::GATEWAY_GOTCHAS['general'],
        ];
    }

    /**
     * Resolve a user-supplied gateway reference (backing value or enum case name) to a case.
     */
    private function resolveGateway(string $gateway): ?GatewayName
    {
        $gateway = trim($gateway);

        if (($case = GatewayName::tryFrom($gateway)) !== null) {
            return $case;
        }

        foreach (GatewayName::cases() as $candidate) {
            if (strcasecmp($candidate->name, $gateway) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Build the PHP snippet for one gateway + operation from the interface + request DTO.
     */
    private function renderTemplate(GatewayName $case, string $operation): string
    {
        $method = new ReflectionMethod(PaymentGatewayInterface::class, $operation);
        $uses = [
            'Hyprpay\\Payments\\Application\\PaymentGatewayFactory',
            'Hyprpay\\Payments\\Domain\\Enum\\GatewayName',
        ];

        $call = $this->renderCall($method, $uses);

        $returnType = $method->getReturnType();
        $returnFqcn = $this->hyprpayTypeName($returnType);

        if ($returnFqcn !== null) {
            $uses[] = $returnFqcn;
        }

        $uses = array_values(array_unique($uses));
        sort($uses);
        $useLines = implode("\n", array_map(static fn (string $u): string => "use {$u};", $uses));

        $returnDisplay = $this->renderReturnDisplay($returnType);

        return <<<PHP
        <?php

        {$useLines}

        /** @var PaymentGatewayFactory \$factory */
        \$gateway = \$factory->make(GatewayName::{$case->name});

        /** @var {$returnDisplay} \$result */
        \$result = \$gateway->{$call};
        PHP;
    }

    /**
     * Render a return type for a @var annotation, preferring the SDK's short class name.
     */
    private function renderReturnDisplay(?\ReflectionType $type): string
    {
        if (! $type instanceof ReflectionNamedType) {
            return 'mixed';
        }

        $name = $type->getName();
        $nullable = $type->allowsNull() && $name !== 'null' && $name !== 'mixed';
        $short = str_starts_with($name, 'Hyprpay\\Payments\\') ? $this->shortName($name) : $name;

        return ($nullable ? '?' : '').$short;
    }

    /**
     * Render the operation call, expanding a single request-DTO argument when present.
     *
     * @param  list<string>  $uses
     */
    private function renderCall(ReflectionMethod $method, array &$uses): string
    {
        $parameters = $method->getParameters();

        if (count($parameters) === 1 && ($dto = $this->hyprpayTypeName($parameters[0]->getType())) !== null) {
            $uses[] = $dto;

            return sprintf("%s(new %s(\n%s\n))", $method->getName(), $this->shortName($dto), $this->renderDtoArgs($dto, $uses));
        }

        $args = [];
        foreach ($parameters as $parameter) {
            $args[] = $this->placeholder($parameter, $uses);
        }

        return sprintf('%s(%s)', $method->getName(), implode(', ', $args));
    }

    /**
     * Render the named-argument body for a request DTO constructor, one argument per line.
     *
     * Required arguments are emitted live; optional ones are shown commented with their default.
     *
     * @param  list<string>  $uses
     */
    private function renderDtoArgs(string $dtoFqcn, array &$uses): string
    {
        $constructor = (new \ReflectionClass($dtoFqcn))->getConstructor();

        if ($constructor === null) {
            return '';
        }

        $lines = [];
        foreach ($constructor->getParameters() as $parameter) {
            $value = $this->placeholder($parameter, $uses);
            $line = sprintf('    %s: %s,', $parameter->getName(), $value);

            $lines[] = $parameter->isOptional() ? '    // '.ltrim($line) : $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Produce a placeholder expression for a parameter, based on its type.
     *
     * @param  list<string>  $uses
     */
    private function placeholder(ReflectionParameter $parameter, array &$uses): string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType) {
            return "'…'";
        }

        $name = $type->getName();

        if ($name === 'Hyprpay\\Payments\\Domain\\ValueObject\\Money') {
            $uses[] = $name;

            return "Money::minor(10000, 'USD')";
        }

        if (enum_exists($name)) {
            $uses[] = $name;
            $first = (new ReflectionEnum($name))->getCases()[0] ?? null;

            return $first !== null ? $this->shortName($name).'::'.$first->getName() : "'…'";
        }

        if (str_starts_with($name, 'Hyprpay\\Payments\\')) {
            $uses[] = $name;

            return 'new '.$this->shortName($name).'(/* … */)';
        }

        return match ($name) {
            'string' => "'<".$this->kebab($parameter->getName()).">'",
            'int' => $parameter->isDefaultValueAvailable() ? (string) (int) $parameter->getDefaultValue() : '0',
            'float' => '0.0',
            'bool' => $parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() ? 'true' : 'false',
            'array' => '[]',
            default => 'null',
        };
    }

    /**
     * The fully-qualified Hyprpay type behind a reflection type, or null if it is not one.
     */
    private function hyprpayTypeName(?\ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType && str_starts_with($type->getName(), 'Hyprpay\\Payments\\')) {
            return $type->getName();
        }

        return null;
    }

    /**
     * The trailing short name of a fully-qualified class name.
     */
    private function shortName(string $fqcn): string
    {
        return substr((string) strrchr('\\'.$fqcn, '\\'), 1);
    }

    /**
     * Convert a camelCase identifier to a kebab-case placeholder token.
     */
    private function kebab(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $value));
    }

    /**
     * Decode a JSON file into an associative array, tolerating a missing file.
     *
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
