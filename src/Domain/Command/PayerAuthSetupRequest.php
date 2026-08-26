<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Result\PayerAuthSetupResult;

/**
 * Input DTO for the 3-D Secure setup / device data collection (DDC) step.
 *
 * Passed to the gateway's setup operation before enrollment to prime the browser's device
 * data collection. The resulting {@see PayerAuthSetupResult} carries the access token, a
 * reference id, and the device-data-collection URL the checkout page loads in a hidden
 * iframe to fingerprint the browser. That reference id is then supplied on
 * {@see PayerAuthEnrollRequest} to correlate the collected data with enrollment.
 */
final readonly class PayerAuthSetupRequest
{
    /**
     * @param  string  $transientToken  One-time token from the Unified Checkout widget representing the card
     * @param  string|null  $orderReference  Optional merchant order/reference number for reconciliation
     */
    public function __construct(
        public string $transientToken,
        public ?string $orderReference = null,
    ) {}
}
