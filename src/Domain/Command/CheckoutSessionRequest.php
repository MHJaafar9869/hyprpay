<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\CybersourcePaymentType;
use Hyprpay\Payments\Domain\Enum\MandateCompletionType;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Input DTO for starting a checkout/payment session across gateways.
 *
 * Carries the amount plus a superset of options used by the different rails: the
 * CyberSource Unified Checkout fields (target origins, card networks, payment types,
 * 3DS/locale, client version) and the redirect/reference-gateway fields used by Fawry
 * (return URL, customer, payment-method selector, description, and a free-form options
 * bag for method-specific inputs such as card or wallet details). Each gateway reads
 * only the fields it needs; the rest are ignored.
 */
final readonly class CheckoutSessionRequest
{
    /**
     * @param  Money  $money  Amount and currency for the checkout session
     * @param  array<int, string>  $targetOrigins  UC: scheme + host of the page embedding the widget
     * @param  array<int, string>  $allowedCardNetworks  UC: card brands to accept (e.g. VISA, MASTERCARD)
     * @param  array<int, CybersourcePaymentType>  $allowedPaymentTypes  UC: payment types the widget may offer (e.g. PanEntry, GooglePay, ApplePay)
     * @param  string|null  $country  Optional two-letter ISO country code for the transaction
     * @param  string|null  $locale  Optional locale for the widget/hosted UI (e.g. en_US, en-gb)
     * @param  string|null  $orderReference  Merchant order/reference number used for reconciliation
     * @param  bool  $enable3ds  UC: whether to enable 3-D Secure payer authentication
     * @param  BillingAddress|null  $billTo  Optional billing address to prefill
     * @param  string  $clientVersion  UC: version of the Unified Checkout client library to load
     * @param  string|null  $returnUrl  Redirect gateways (Fawry): URL to return the customer to after payment
     * @param  Customer|null  $customer  Optional customer profile (id/email/name) for the payment
     * @param  string|null  $paymentMethod  Gateway-specific method selector (e.g. Fawry: hosted, PayUsingCC, MWALLET, PAYATFAWRY)
     * @param  string|null  $description  Human-readable description of the order/items
     * @param  CheckoutOptions|null  $options  Gateway-specific extras as a typed per-gateway options DTO (e.g. PayPalCheckoutOptions, PaytabsCheckoutOptions). Each driver narrows this to its own CheckoutOptions type; build one from a raw config array with the DTO's own fromArray() when needed.
     * @param  MandateCompletionType|null  $completeMandate  UC v1: when set, the widget orchestrates the whole payment (Decision Manager, 3DS, authorization, TMS) client-side and returns a signed result JWT — Capture for a sale, Auth for an authorization hold. Leave null for the manual transient-token flow.
     * @param  bool  $decisionManager  UC v1 orchestrated flow only: whether the widget runs Decision Manager (and its device fingerprinting) as part of the mandate. Emitted as completeMandate.decisionManager; ignored unless completeMandate is set. Defaults to true so orchestrated payments are fraud-screened.
     * @param  bool  $createToken  UC v1 orchestrated flow only: whether the widget vaults the payment credential in TMS as part of the mandate, so the signed result JWT carries reusable customer/payment-instrument/instrument-identifier token ids for later stored-credential charges. Emitted as completeMandate.tms.tokenCreate; ignored unless completeMandate is set. Defaults to false.
     * @param  array<int, string>  $tokenTypes  UC v1 orchestrated flow only: which TMS token types to create when $createToken is set (e.g. customer, paymentInstrument, instrumentIdentifier). Emitted as completeMandate.tms.tokenTypes; when empty CyberSource uses the merchant vault default.
     */
    public function __construct(
        public Money $money,
        public array $targetOrigins = [],
        public array $allowedCardNetworks = ['VISA', 'MASTERCARD'],
        public array $allowedPaymentTypes = [CybersourcePaymentType::PanEntry],
        public ?string $country = null,
        public ?string $locale = null,
        public ?string $orderReference = null,
        public bool $enable3ds = true,
        public ?BillingAddress $billTo = null,
        public string $clientVersion = '0.34',
        public ?string $returnUrl = null,
        public ?Customer $customer = null,
        public ?string $paymentMethod = null,
        public ?string $description = null,
        public ?CheckoutOptions $options = null,
        public ?MandateCompletionType $completeMandate = null,
        public bool $decisionManager = true,
        public bool $createToken = false,
        public array $tokenTypes = ['customer', 'paymentInstrument', 'instrumentIdentifier'],
    ) {}

    /**
     * Render the options as a raw key/value array (empty when none were supplied).
     *
     * Lets a driver resolve any {@see CheckoutOptions} — including another gateway's DTO —
     * back to its own typed options via that DTO's fromArray(), which ignores unknown keys.
     *
     * @return array<string, mixed>
     */
    public function optionsArray(): array
    {
        return $this->options?->toArray() ?? [];
    }
}
