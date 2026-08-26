<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

/**
 * Result DTO returned by the 3-D Secure setup / device data collection (DDC) step.
 *
 * Carries the access token and device-data-collection URL the checkout page loads in a
 * hidden iframe to fingerprint the browser, plus the reference id that ties the collected
 * device data to the subsequent enrollment.
 */
final readonly class PayerAuthSetupResult
{
    /**
     * @param  bool  $success  Whether the setup step completed successfully
     * @param  string  $status  Gateway setup status (e.g. COMPLETED)
     * @param  string|null  $accessToken  JWT posted to the device-data-collection URL to launch collection
     * @param  string|null  $referenceId  Reference id correlating the collected device data with enrollment
     * @param  string|null  $deviceDataCollectionUrl  URL the checkout page loads in a hidden iframe to collect browser device data
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public bool $success,
        public string $status,
        public ?string $accessToken = null,
        public ?string $referenceId = null,
        public ?string $deviceDataCollectionUrl = null,
        public array $raw = [],
    ) {}

    /**
     * Determine whether the gateway returned a device-data-collection URL to load.
     *
     * Returns true when a collection URL is present, meaning the checkout page should load
     * it in a hidden iframe to collect the browser device data before enrolling the payer.
     */
    public function requiresDeviceDataCollection(): bool
    {
        return filled($this->deviceDataCollectionUrl);
    }
}
