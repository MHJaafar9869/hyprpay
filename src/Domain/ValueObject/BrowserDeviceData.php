<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

use Hyprpay\Payments\Domain\Command\PayerAuthEnrollRequest;
use Hyprpay\Payments\Domain\Command\ValidatePayerAuthRequest;

/**
 * Value object holding the payer's browser device data for 3-D Secure authentication.
 *
 * Carries the EMV 3-D Secure browser fields collected on the checkout page — the user
 * agent, screen and locale characteristics, and the client IP — plus the challenge window
 * size. Passed on {@see PayerAuthEnrollRequest} and {@see ValidatePayerAuthRequest} so a
 * gateway can populate its device block; richer device data lets the issuer risk-assess the
 * transaction and grant a frictionless (no-challenge) authentication more often. Every field
 * is optional; only those supplied are sent.
 */
final readonly class BrowserDeviceData
{
    /**
     * @param  string|null  $ipAddress  Client IP address the request originated from
     * @param  string|null  $userAgent  Value of the browser's User-Agent header
     * @param  string|null  $acceptHeaders  Value of the browser's Accept header
     * @param  int|null  $colorDepth  Screen colour depth in bits per pixel
     * @param  bool|null  $javaEnabled  Whether the browser can run Java
     * @param  bool|null  $javaScriptEnabled  Whether the browser can run JavaScript
     * @param  string|null  $language  Browser language per IETF BCP 47 (e.g. en-US)
     * @param  int|null  $screenHeight  Total screen height in pixels
     * @param  int|null  $screenWidth  Total screen width in pixels
     * @param  int|null  $timeZone  Difference in minutes between UTC and the browser's local time
     * @param  string|null  $challengeWindowSize  Preferred 3-D Secure challenge window size (e.g. FULL_SCREEN)
     */
    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $acceptHeaders = null,
        public ?int $colorDepth = null,
        public ?bool $javaEnabled = null,
        public ?bool $javaScriptEnabled = null,
        public ?string $language = null,
        public ?int $screenHeight = null,
        public ?int $screenWidth = null,
        public ?int $timeZone = null,
        public ?string $challengeWindowSize = null,
    ) {}

    /**
     * Determine whether no device fields are populated.
     *
     * Returns true when every field is null, meaning there is nothing to send to the gateway.
     */
    public function isEmpty(): bool
    {
        return $this->ipAddress === null
            && $this->userAgent === null
            && $this->acceptHeaders === null
            && $this->colorDepth === null
            && $this->javaEnabled === null
            && $this->javaScriptEnabled === null
            && $this->language === null
            && $this->screenHeight === null
            && $this->screenWidth === null
            && $this->timeZone === null
            && $this->challengeWindowSize === null;
    }
}
