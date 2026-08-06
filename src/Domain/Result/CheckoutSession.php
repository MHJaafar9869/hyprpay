<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

/**
 * Result DTO describing how the customer completes a started payment.
 *
 * The gateway-agnostic result of a create-checkout-session call. Different rails
 * populate different fields: CyberSource Unified Checkout returns a capture-context
 * JWT (plus the widget client library), while redirect/reference gateways such as
 * Fawry return a hosted redirect URL, a pay-at-outlet reference number, and/or a
 * wallet QR code. Only the fields relevant to the chosen gateway/method are set.
 */
final readonly class CheckoutSession
{
    /**
     * @param  string|null  $jwt  Signed capture-context JWT that initialises a widget-based checkout (e.g. CyberSource UC)
     * @param  string|null  $clientLibrary  URL of the widget's JavaScript client library, when applicable
     * @param  string|null  $clientLibraryIntegrity  Subresource-integrity hash for the client library script
     * @param  string|null  $redirectUrl  URL to redirect the customer to (hosted checkout or 3-D Secure step-up)
     * @param  string|null  $reference  Gateway reference the customer uses to pay (e.g. Fawry reference number)
     * @param  string|null  $qrCode  QR payload/image the customer scans to pay (e.g. wallet QR)
     * @param  string|null  $merchantReference  Merchant reference (order number) associated with the session
     * @param  array<string, mixed>  $raw  Raw decoded gateway response/claims, when available
     */
    public function __construct(
        public ?string $jwt = null,
        public ?string $clientLibrary = null,
        public ?string $clientLibraryIntegrity = null,
        public ?string $redirectUrl = null,
        public ?string $reference = null,
        public ?string $qrCode = null,
        public ?string $merchantReference = null,
        public array $raw = [],
    ) {}
}
