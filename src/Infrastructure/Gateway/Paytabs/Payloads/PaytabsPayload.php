<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs\Payloads;

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\ReversalRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\VoidRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Builds the PayTabs PT2 request bodies for each operation and integration type.
 *
 * Every body carries the merchant `profile_id`. New payments select a `tran_type`
 * (sale/auth) and reach PayTabs through one of the integration types — the Hosted
 * Payment Page, an Invoice ({@see invoice()}), the iframe Managed Form ({@see managed()}),
 * an Own Form charge of a browser-generated `payment_token` ({@see ownForm()}), or a
 * reusable PayLink ({@see payLink()}) — while follow-ons (capture/refund/void) and
 * token-based charges ({@see storedCredential()}) post to the shared endpoint too.
 * Amounts are sent as exact decimal strings and null fields are dropped so a body only
 * ever contains what the caller supplied.
 */
final class PaytabsPayload
{
    /**
     * Build the base body for a new sale or authorization on the Hosted Payment Page.
     *
     * The `paymentMethod` selector chooses the transaction type: `auth` places a hold
     * to capture later, anything else is an immediate `sale`. `options['tran_class']`
     * overrides the default `ecom` class (e.g. for `moto`), `options['webhook_url']`
     * sets the server-to-server IPN callback, `options['tokenise']` (1–6) asks PayTabs
     * to return a reusable card token, `options['agreement']` starts a repeat-billing
     * agreement PayTabs then auto-bills on the given schedule, and `options['iframe']`
     * renders the page in an embedded iframe instead of redirecting.
     *
     * @return array<string, mixed>
     */
    public static function hosted(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
    {
        $customerDetails = self::customerDetails($request->customer, $request->billTo);

        $body = self::withoutNulls([
            'profile_id' => self::profileId($credentials),
            'tran_type' => self::tranType($request->paymentMethod),
            'tran_class' => Value::string($request->options['tran_class'] ?? null, 'ecom'),
            'cart_id' => $request->orderReference,
            'cart_description' => $request->description ?? 'Payment',
            'cart_currency' => $request->money->currency,
            'cart_amount' => $request->money->toDecimalString(),
            'paypage_lang' => self::language($request, $credentials),
            'return' => $request->returnUrl,
            'callback' => Value::nullableString($request->options['webhook_url'] ?? null),
            'tokenise' => isset($request->options['tokenise']) ? Value::int($request->options['tokenise']) : null,
            'agreement' => self::agreement($request),
            'split_payout' => self::splitPayout($request),
            'customer_details' => $customerDetails === [] ? null : $customerDetails,
        ]);

        return array_merge($body, self::framedFields($request, false));
    }

    /**
     * Build the Invoice body — the Hosted body plus an itemised invoice.
     *
     * Uses `options['line_items']` when supplied, otherwise a single line item for the
     * full amount. PayTabs returns an `invoice_link` to email or share.
     *
     * @return array<string, mixed>
     */
    public static function invoice(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
    {
        $body = self::hosted($request, $credentials);
        $body['invoice'] = ['line_items' => self::lineItems($request)];

        return $body;
    }

    /**
     * Build the Managed Form body — the Hosted body forced to render inside an iframe.
     *
     * @return array<string, mixed>
     */
    public static function managed(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
    {
        return array_merge(self::hosted($request, $credentials), self::framedFields($request, true));
    }

    /**
     * Build the PayLink body used to create a reusable, shareable payment link.
     *
     * @return array<string, mixed>
     */
    public static function payLink(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
    {
        return self::withoutNulls([
            'profile_id' => self::profileId($credentials),
            'link_title' => $request->description ?? 'Payment',
            'cart_id' => $request->orderReference,
            'cart_description' => $request->description ?? 'Payment',
            'cart_currency' => $request->money->currency,
            'cart_amount' => $request->money->toDecimalString(),
            'link_status' => true,
            'return_url' => $request->returnUrl,
        ]);
    }

    /**
     * Build the Own Form charge body from a browser-generated `payment_token`.
     *
     * Captures immediately (`sale`) unless the charge asks to authorize only (`auth`).
     * A 3-D Secure card yields a `redirect_url` in the response; otherwise the payment
     * result is returned inline.
     *
     * @return array<string, mixed>
     */
    public static function ownForm(ChargeRequest $request, GatewayCredentials $credentials): array
    {
        $customerDetails = self::customerDetails($request->customer, $request->billTo);

        return self::withoutNulls([
            'profile_id' => self::profileId($credentials),
            'tran_type' => $request->capture ? 'sale' : 'auth',
            'tran_class' => 'ecom',
            'cart_id' => $request->orderReference,
            'cart_description' => 'Payment',
            'cart_currency' => $request->money->currency,
            'cart_amount' => $request->money->toDecimalString(),
            'payment_token' => $request->transientToken,
            'customer_details' => $customerDetails === [] ? null : $customerDetails,
        ]);
    }

    /**
     * Build the token-based (stored credential) charge body.
     *
     * A merchant-initiated charge is sent as `recurring` (requires Recurring mode on the
     * PayTabs profile); a customer-initiated charge is a normal `ecom` transaction. The
     * saved token is charged directly, server-to-server, with no redirect.
     *
     * @return array<string, mixed>
     */
    public static function storedCredential(StoredCredentialChargeRequest $request, GatewayCredentials $credentials): array
    {
        return self::withoutNulls([
            'profile_id' => self::profileId($credentials),
            'tran_type' => 'sale',
            'tran_class' => $request->initiator === CredentialInitiator::Merchant ? 'recurring' : 'ecom',
            'cart_id' => $request->orderReference ?? $request->paymentInstrumentId,
            'cart_description' => 'Stored credential charge',
            'cart_currency' => $request->money->currency,
            'cart_amount' => $request->money->toDecimalString(),
            'token' => $request->paymentInstrumentId,
        ]);
    }

    /**
     * Build the capture (settle) body for a prior authorization hold.
     *
     * @return array<string, mixed>
     */
    public static function capture(CaptureRequest $request, GatewayCredentials $credentials): array
    {
        return [
            'profile_id' => self::profileId($credentials),
            'tran_type' => 'capture',
            'tran_class' => 'ecom',
            'tran_ref' => $request->transactionId,
            'cart_id' => $request->orderReference ?? $request->transactionId,
            'cart_currency' => $request->money->currency,
            'cart_amount' => $request->money->toDecimalString(),
            'cart_description' => 'Capture for '.($request->orderReference ?? $request->transactionId),
        ];
    }

    /**
     * Build the refund body for a settled transaction.
     *
     * @return array<string, mixed>
     */
    public static function refund(RefundRequest $request, GatewayCredentials $credentials): array
    {
        return [
            'profile_id' => self::profileId($credentials),
            'tran_type' => 'refund',
            'tran_class' => 'ecom',
            'tran_ref' => $request->transactionId,
            'cart_id' => $request->orderReference ?? $request->transactionId,
            'cart_currency' => $request->money->currency,
            'cart_amount' => $request->money->toDecimalString(),
            'cart_description' => $request->reason ?? 'Refund for '.($request->orderReference ?? $request->transactionId),
        ];
    }

    /**
     * Build the void body for an authorized-but-uncaptured transaction.
     *
     * The full amount is implied (no `cart_amount`), so PayTabs voids the whole hold.
     *
     * @return array<string, mixed>
     */
    public static function void(VoidRequest $request, GatewayCredentials $credentials): array
    {
        return [
            'profile_id' => self::profileId($credentials),
            'tran_type' => 'void',
            'tran_class' => 'ecom',
            'tran_ref' => $request->transactionId,
            'cart_id' => $request->orderReference ?? $request->transactionId,
        ];
    }

    /**
     * Build the release body that reverses (all or part of) an authorization hold.
     *
     * PayTabs distinguishes release from void by amount: a partial `cart_amount`
     * releases that portion of the hold, while passing the full amount is treated as a
     * void. The amount comes from the reversal request.
     *
     * @return array<string, mixed>
     */
    public static function release(ReversalRequest $request, GatewayCredentials $credentials): array
    {
        return [
            'profile_id' => self::profileId($credentials),
            'tran_type' => 'release',
            'tran_class' => 'ecom',
            'tran_ref' => $request->transactionId,
            'cart_id' => $request->orderReference ?? $request->transactionId,
            'cart_currency' => $request->money->currency,
            'cart_amount' => $request->money->toDecimalString(),
            'cart_description' => 'Release for '.($request->orderReference ?? $request->transactionId),
        ];
    }

    /**
     * Build the transaction-query body used to look a transaction up by reference.
     *
     * @return array<string, mixed>
     */
    public static function query(string $tranRef, GatewayCredentials $credentials): array
    {
        return [
            'profile_id' => self::profileId($credentials),
            'tran_ref' => $tranRef,
        ];
    }

    /**
     * Build the token-deletion body used to revoke a saved card token.
     *
     * @return array<string, mixed>
     */
    public static function deleteToken(string $token, GatewayCredentials $credentials): array
    {
        return [
            'profile_id' => self::profileId($credentials),
            'token' => $token,
        ];
    }

    private static function profileId(GatewayCredentials $credentials): int
    {
        return (int) $credentials->merchantId;
    }

    private static function tranType(?string $paymentMethod): string
    {
        return strtolower((string) $paymentMethod) === 'auth' ? 'auth' : 'sale';
    }

    private static function language(CheckoutSessionRequest $request, GatewayCredentials $credentials): string
    {
        $locale = $request->locale ?? $credentials->locale;

        return strtolower(substr($locale, 0, 2)) ?: 'en';
    }

    /**
     * Assemble the repeat-billing agreement object from the checkout options.
     *
     * Once the customer completes the initial payment and consents, PayTabs tokenises
     * the card and auto-bills the schedule (there is no per-cycle API call). The
     * agreement currency and initial amount default to the checkout's money, which
     * PayTabs requires them to match.
     *
     * @return array<array-key, mixed>|null
     */
    private static function agreement(CheckoutSessionRequest $request): ?array
    {
        $agreement = $request->options['agreement'] ?? null;

        if (! is_array($agreement) || $agreement === []) {
            return null;
        }

        return array_merge([
            'agreement_currency' => $request->money->currency,
            'initial_amount' => $request->money->toDecimalString(),
        ], $agreement);
    }

    /**
     * Build the iframe/embedded-mode (framed) fields when requested.
     *
     * Enabled by `options['iframe']` (or forced for the Managed Form): PayTabs then
     * returns a redirect_url meant to be embedded in an `<iframe>` rather than
     * redirected to. `framed_return_top` / `framed_return_parent` control reload
     * behaviour on the post-payment return, and `framed_message_target` (an HTTPS URL
     * on your domain) receives a JS `postMessage` so you can close the iframe.
     *
     * @return array<string, mixed>
     */
    private static function framedFields(CheckoutSessionRequest $request, bool $force): array
    {
        $enabled = $force || filter_var($request->options['iframe'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            return [];
        }

        return self::withoutNulls([
            'framed' => true,
            'framed_return_top' => self::optionalBool($request, 'framed_return_top'),
            'framed_return_parent' => self::optionalBool($request, 'framed_return_parent'),
            'framed_message_target' => Value::nullableString($request->options['framed_message_target'] ?? null),
        ]);
    }

    /**
     * Read an optional boolean checkout option, or null when it is not set.
     */
    private static function optionalBool(CheckoutSessionRequest $request, string $key): ?bool
    {
        if (! array_key_exists($key, $request->options)) {
            return null;
        }

        return filter_var($request->options[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Resolve the split-payout stakeholders from the checkout options.
     *
     * Passed through as-is: an array of entities, each with its `item_total`,
     * `msc_flag`, and `beneficiary` details. PayTabs splits the settled funds across
     * them after the customer pays. Returns null when no split is configured.
     *
     * @return array<int, mixed>|null
     */
    private static function splitPayout(CheckoutSessionRequest $request): ?array
    {
        $split = $request->options['split_payout'] ?? null;

        if (! is_array($split) || $split === []) {
            return null;
        }

        return array_values($split);
    }

    /**
     * Resolve the invoice line items, defaulting to a single item for the full amount.
     *
     * @return array<int, mixed>
     */
    private static function lineItems(CheckoutSessionRequest $request): array
    {
        $items = $request->options['line_items'] ?? null;

        if (is_array($items) && $items !== []) {
            return array_values($items);
        }

        return [[
            'unit_cost' => $request->money->toDecimalString(),
            'quantity' => 1,
        ]];
    }

    /**
     * Assemble the customer_details object from a customer profile and billing address.
     *
     * @return array<string, string>
     */
    private static function customerDetails(?Customer $customer, ?BillingAddress $billTo): array
    {
        $details = [
            'name' => self::fullName(
                $customer->firstName ?? $billTo?->firstName,
                $customer->lastName ?? $billTo?->lastName,
            ),
            'email' => $customer->email ?? $billTo?->email,
            'phone' => $billTo?->phoneNumber,
            'street1' => $billTo?->address1,
            'city' => $billTo?->locality,
            'state' => $billTo?->administrativeArea,
            'country' => $billTo?->country,
            'zip' => $billTo?->postalCode,
        ];

        return array_filter($details, static fn (?string $value): bool => $value !== null && $value !== '');
    }

    private static function fullName(?string $first, ?string $last): ?string
    {
        $name = trim(trim((string) $first).' '.trim((string) $last));

        return $name === '' ? null : $name;
    }

    /**
     * Drop null-valued fields while preserving zero/false/empty-string values.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $body): array
    {
        return array_filter($body, static fn ($value): bool => $value !== null);
    }
}
