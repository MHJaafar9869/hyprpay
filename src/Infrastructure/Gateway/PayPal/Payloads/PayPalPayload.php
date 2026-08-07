<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal\Payloads;

use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\Command\RefundRequest;
use Hyprpay\Payments\Domain\Command\StoredCredentialChargeRequest;
use Hyprpay\Payments\Domain\Command\TokenizeInstrumentRequest;
use Hyprpay\Payments\Domain\Enum\CredentialInitiator;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalShippingPreference;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalUserAction;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\PayPalCheckoutOptions;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Builds the PayPal REST request bodies for each operation the driver performs.
 *
 * A new payment is an Orders v2 order ({@see order()}): its `intent` (CAPTURE or
 * AUTHORIZE) is chosen from the checkout's payment method, the `purchase_units` carry the
 * amount and merchant reference, and a `payment_source.paypal.experience_context` supplies
 * the return/cancel URLs PayPal uses to build the buyer-approval link. Follow-ons build
 * the Payments v2 bodies — {@see captureAuthorization()}, {@see refundCapture()} — while
 * {@see storedCredentialOrder()} charges a vaulted card, {@see setupToken()}/{@see paymentToken()}
 * drive the two-step vault flow, and {@see verifyWebhookSignature()} assembles the
 * webhook-verification body from the transmission headers. Amounts are exact decimal
 * strings and null fields are dropped so a body only contains what the caller supplied.
 */
final class PayPalPayload
{
    /**
     * Build a create-order body for a new checkout.
     *
     * The `paymentMethod` selector sets the intent — `authorize` places a hold to capture
     * later, anything else captures on approval. When a return URL is supplied, the PayPal
     * experience context is attached so PayPal returns a buyer-approval link; `custom_id`
     * carries the merchant order reference for reconciliation.
     *
     * @return array<string, mixed>
     */
    public static function order(CheckoutSessionRequest $request): array
    {
        $purchaseUnit = self::withoutNulls([
            'custom_id' => $request->orderReference,
            'description' => $request->description,
            'amount' => self::amount($request->money),
        ]);

        $experienceContext = self::experienceContext($request);

        return self::withoutNulls([
            'intent' => self::intent($request->paymentMethod),
            'purchase_units' => [$purchaseUnit],
            'payment_source' => $experienceContext === []
                ? null
                : ['paypal' => ['experience_context' => $experienceContext]],
        ]);
    }

    /**
     * Build a create-order body that charges a vaulted card (stored credential).
     *
     * References the saved card by its vault id and attaches the network stored-credential
     * metadata (initiator, first-vs-subsequent use, and scheduled/unscheduled type) so the
     * charge follows card-on-file rules. Intended to be created then captured.
     *
     * @return array<string, mixed>
     */
    public static function storedCredentialOrder(StoredCredentialChargeRequest $request): array
    {
        $merchantInitiated = $request->initiator === CredentialInitiator::Merchant;

        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [self::withoutNulls([
                'custom_id' => $request->orderReference,
                'amount' => self::amount($request->money),
            ])],
            'payment_source' => [
                'card' => [
                    'vault_id' => $request->paymentInstrumentId,
                    'stored_credential' => [
                        'payment_initiator' => $merchantInitiated ? 'MERCHANT' : 'CUSTOMER',
                        'payment_type' => $merchantInitiated ? 'RECURRING' : 'ONE_TIME',
                        'usage' => $request->isFirstCharge ? 'FIRST' : 'SUBSEQUENT',
                    ],
                ],
            ],
        ];
    }

    /**
     * Build the body that captures funds on an authorized payment.
     *
     * The captured amount is the request's amount, so a smaller amount performs a partial
     * capture; `final_capture` marks it as the last capture against the authorization.
     *
     * @return array<string, mixed>
     */
    public static function captureAuthorization(CaptureRequest $request): array
    {
        return self::withoutNulls([
            'amount' => self::amount($request->money),
            'invoice_id' => $request->orderReference,
            'final_capture' => true,
        ]);
    }

    /**
     * Build the body that refunds all or part of a captured payment.
     *
     * @return array<string, mixed>
     */
    public static function refundCapture(RefundRequest $request): array
    {
        return self::withoutNulls([
            'amount' => self::amount($request->money),
            'invoice_id' => $request->orderReference,
            'note_to_payer' => $request->reason,
        ]);
    }

    /**
     * Build a setup-token body that stores a raw card for later vaulting.
     *
     * PayPal returns a setup token that {@see paymentToken()} exchanges for a permanent
     * payment (vault) token. The expiry is rendered in PayPal's `YYYY-MM` format.
     *
     * @return array<string, mixed>
     */
    public static function setupToken(TokenizeInstrumentRequest $request): array
    {
        $card = self::withoutNulls([
            'number' => $request->cardNumber,
            'expiry' => sprintf('%04d-%02d', (int) $request->expirationYear, (int) $request->expirationMonth),
            'name' => self::cardholderName($request->billTo),
            'billing_address' => self::billingAddress($request->billTo),
        ]);

        return ['payment_source' => ['card' => $card]];
    }

    /**
     * Build the body that exchanges a setup token for a permanent payment (vault) token.
     *
     * @return array<string, mixed>
     */
    public static function paymentToken(string $setupTokenId): array
    {
        return ['payment_source' => ['token' => ['id' => $setupTokenId, 'type' => 'SETUP_TOKEN']]];
    }

    /**
     * Build the webhook-signature verification body from the transmission headers.
     *
     * PayPal signs the notification with the fields carried in the `PayPal-Transmission-*`,
     * `PayPal-Cert-Url`, and `PayPal-Auth-Algo` headers; the merchant's configured
     * `webhook_id` scopes the check, and `webhook_event` is the decoded event body.
     *
     * @param  array<string, string|array<int, string>>  $headers
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function verifyWebhookSignature(string $webhookId, array $headers, array $event): array
    {
        return [
            'webhook_id' => $webhookId,
            'transmission_id' => self::header($headers, 'paypal-transmission-id'),
            'transmission_time' => self::header($headers, 'paypal-transmission-time'),
            'cert_url' => self::header($headers, 'paypal-cert-url'),
            'auth_algo' => self::header($headers, 'paypal-auth-algo'),
            'transmission_sig' => self::header($headers, 'paypal-transmission-sig'),
            'webhook_event' => $event,
        ];
    }

    /**
     * Build a PayPal money object (currency code + exact decimal value) from Money.
     *
     * @return array<string, string>
     */
    private static function amount(Money $money): array
    {
        return [
            'currency_code' => $money->currency,
            'value' => $money->toDecimalString(),
        ];
    }

    /**
     * Resolve the order intent from the checkout's payment method.
     */
    private static function intent(?string $paymentMethod): string
    {
        return strtolower((string) $paymentMethod) === 'authorize' ? 'AUTHORIZE' : 'CAPTURE';
    }

    /**
     * Assemble the PayPal experience context from the typed checkout options.
     *
     * Returns an empty array when no return URL is set, in which case the order is created
     * without a buyer-approval link (e.g. a direct card flow handled elsewhere). The button
     * defaults to PAY_NOW and shipping to NO_SHIPPING; the cancel URL and locale fall back
     * to the request's return URL and locale.
     *
     * @return array<string, string>
     */
    private static function experienceContext(CheckoutSessionRequest $request): array
    {
        if ($request->returnUrl === null) {
            return [];
        }

        $options = self::options($request);

        $context = [
            'return_url' => $request->returnUrl,
            'cancel_url' => $options->cancelUrl ?? $request->returnUrl,
            'brand_name' => $options->brandName,
            'locale' => $options->locale ?? $request->locale,
            'user_action' => ($options->userAction ?? PayPalUserAction::PayNow)->value,
            'shipping_preference' => ($options->shippingPreference ?? PayPalShippingPreference::NoShipping)->value,
            'payment_method_preference' => $options->paymentMethodPreference?->value,
        ];

        return array_filter($context, static fn (?string $value): bool => $value !== null);
    }

    /**
     * Resolve the request's options to a typed PayPalCheckoutOptions.
     *
     * Uses the DTO directly when provided, otherwise maps a legacy options array through
     * {@see PayPalCheckoutOptions::fromArray()} so both call styles are supported.
     */
    private static function options(CheckoutSessionRequest $request): PayPalCheckoutOptions
    {
        return $request->options instanceof PayPalCheckoutOptions
            ? $request->options
            : PayPalCheckoutOptions::fromArray($request->optionsArray());
    }

    /**
     * Map the SDK billing address onto PayPal's card `billing_address` shape.
     *
     * @return array<string, string>|null
     */
    private static function billingAddress(?BillingAddress $billTo): ?array
    {
        if (! $billTo instanceof BillingAddress) {
            return null;
        }

        $address = array_filter([
            'address_line_1' => $billTo->address1,
            'address_line_2' => $billTo->address2,
            'admin_area_2' => $billTo->locality,
            'admin_area_1' => $billTo->administrativeArea,
            'postal_code' => $billTo->postalCode,
            'country_code' => $billTo->country,
        ], static fn (?string $value): bool => $value !== null);

        return $address === [] ? null : $address;
    }

    /**
     * Build the cardholder name from the billing address, or null when absent.
     */
    private static function cardholderName(?BillingAddress $billTo): ?string
    {
        if (! $billTo instanceof BillingAddress) {
            return null;
        }

        $name = trim(trim((string) $billTo->firstName).' '.trim((string) $billTo->lastName));

        return $name === '' ? null : $name;
    }

    /**
     * Read a header by name (case-insensitively), taking the first value of a list.
     *
     * @param  array<string, string|array<int, string>>  $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return Value::nullableString(is_array($value) ? ($value[0] ?? null) : $value);
            }
        }

        return null;
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
