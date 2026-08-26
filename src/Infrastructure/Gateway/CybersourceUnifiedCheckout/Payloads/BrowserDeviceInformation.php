<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads;

use Hyprpay\Payments\Domain\ValueObject\BrowserDeviceData;

/**
 * Maps the payer's browser device data onto the CyberSource deviceInformation block.
 *
 * Translates the SDK's {@see BrowserDeviceData} value object — the EMV 3-D Secure browser
 * fields collected on the checkout page — into the CyberSource field names used by the
 * payer-authentication (risk) endpoints, so the issuer can risk-assess the browser and grant
 * a frictionless (no-challenge) authentication more often. Only populated fields are emitted;
 * the numeric screen/colour/timezone values are rendered as strings, as CyberSource expects.
 */
final class BrowserDeviceInformation
{
    /**
     * The browser deviceInformation fields, or an empty array when no data is supplied.
     *
     * @param  BrowserDeviceData|null  $device  The collected browser device data, or null to omit.
     * @return array<string, mixed>
     */
    public static function fields(?BrowserDeviceData $device): array
    {
        if (! $device instanceof BrowserDeviceData || $device->isEmpty()) {
            return [];
        }

        return array_filter([
            'ipAddress' => $device->ipAddress,
            'httpAcceptContent' => $device->acceptHeaders,
            'httpBrowserLanguage' => $device->language,
            'httpBrowserJavaEnabled' => $device->javaEnabled,
            'httpBrowserJavaScriptEnabled' => $device->javaScriptEnabled,
            'httpBrowserColorDepth' => self::stringOrNull($device->colorDepth),
            'httpBrowserScreenHeight' => self::stringOrNull($device->screenHeight),
            'httpBrowserScreenWidth' => self::stringOrNull($device->screenWidth),
            'httpBrowserTimeDifference' => self::stringOrNull($device->timeZone),
            'userAgentBrowserValue' => $device->userAgent,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Render an integer field as the string CyberSource expects, or null to omit it.
     */
    private static function stringOrNull(?int $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
