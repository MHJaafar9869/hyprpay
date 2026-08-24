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
                'detail' => 'Retrying a permanently-declined credential (expired/stolen/invalid account; Mastercard merchant-advice codes 01/03/04/21/99, Visa 1 = ISSUER_WILL_NEVER_APPROVE) never succeeds and can draw processor penalties. Read both errorInformation.reason and processorInformation.merchantAdvice.code — DeclineClassifier::classify()/fromResult() does this and returns a DeclineOutcome flagging isPermanent vs isRetryable(). Retry only transient declines (insufficient funds, issuer unavailable), and no more than 15 times over 30 days per the card-network mandates.',
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
