<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Payloads;

use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\BrowserDeviceData;
use Hyprpay\Payments\Domain\ValueObject\Money;

/**
 * Shared MPGS request-body fragments reused across the payload builders.
 *
 * Centralises the wire-format shapes MPGS repeats — the order and transaction amount
 * blocks, the card/token `sourceOfFunds`, and the billing address — so each payload
 * builder composes them instead of re-deriving the structure.
 */
final class MpgsPayloadParts
{
    /**
     * Build the `order` block carrying the amount, currency, and optional description.
     *
     * @param  Money  $money  Amount and currency for the order.
     * @param  string|null  $description  Optional order description.
     * @return array<string, string>
     */
    public static function order(Money $money, ?string $description = null): array
    {
        return array_filter([
            'amount' => $money->toDecimalString(),
            'currency' => $money->currency,
            'description' => $description,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Build the `transaction` block carrying the amount and currency (used by capture and
     * refund, which settle an amount against an existing order).
     *
     * @param  Money  $money  Amount and currency to settle.
     * @return array<string, string>
     */
    public static function transactionAmount(Money $money): array
    {
        return [
            'amount' => $money->toDecimalString(),
            'currency' => $money->currency,
        ];
    }

    /**
     * Build a card `sourceOfFunds` block from raw card details.
     *
     * @param  string  $number  Card primary account number (PAN).
     * @param  string  $month  Two-digit expiry month (MM).
     * @param  string  $year  Expiry year, two- or four-digit (normalised to MPGS's two-digit YY).
     * @param  string|null  $securityCode  Optional card security code.
     * @return array<string, mixed>
     */
    public static function cardSourceOfFunds(string $number, string $month, string $year, ?string $securityCode = null): array
    {
        $card = [
            'number' => $number,
            'expiry' => [
                'month' => $month,
                'year' => self::twoDigitYear($year),
            ],
        ];

        if (filled($securityCode)) {
            $card['securityCode'] = $securityCode;
        }

        return [
            'type' => 'CARD',
            'provided' => ['card' => $card],
        ];
    }

    /**
     * Build the MPGS `billing` block from a billing address, or an empty array when no
     * address fields are present.
     *
     * @param  BillingAddress|null  $address  Billing address to serialise, if any.
     * @return array<string, mixed>
     */
    public static function billing(?BillingAddress $address): array
    {
        if (! $address instanceof BillingAddress || $address->isEmpty()) {
            return [];
        }

        $addressBlock = array_filter([
            'street' => $address->address1,
            'street2' => $address->address2,
            'city' => $address->locality,
            'stateProvince' => $address->administrativeArea,
            'postcodeZip' => $address->postalCode,
            'country' => $address->country,
        ], static fn (mixed $value): bool => $value !== null);

        return $addressBlock === [] ? [] : ['address' => $addressBlock];
    }

    /**
     * Build the MPGS `device` block from 3-D Secure browser device data, or an empty array
     * when none is present.
     *
     * Maps the EMV 3-D Secure browser fields to MPGS's device shape — the user agent to
     * `device.browser`, the screen/locale characteristics and challenge window size to
     * `device.browserDetails`, and the client IP to `device.ipAddress`. Only populated
     * fields are included; booleans and zero values are preserved.
     *
     * @param  BrowserDeviceData|null  $device  Browser device data to serialise, if any.
     * @return array<string, mixed>
     */
    public static function device(?BrowserDeviceData $device): array
    {
        if (! $device instanceof BrowserDeviceData || $device->isEmpty()) {
            return [];
        }

        $browserDetails = array_filter([
            '3DSecureChallengeWindowSize' => $device->challengeWindowSize,
            'acceptHeaders' => $device->acceptHeaders,
            'colorDepth' => $device->colorDepth,
            'javaEnabled' => $device->javaEnabled,
            'javascriptEnabled' => $device->javaScriptEnabled,
            'language' => $device->language,
            'screenHeight' => $device->screenHeight,
            'screenWidth' => $device->screenWidth,
            'timeZone' => $device->timeZone,
        ], static fn (mixed $value): bool => $value !== null);

        return array_filter([
            'browser' => $device->userAgent,
            'browserDetails' => $browserDetails === [] ? null : $browserDetails,
            'ipAddress' => $device->ipAddress,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Normalise an expiry year to MPGS's two-digit form (YY), leaving two-digit input as is.
     *
     * @param  string  $year  Expiry year, two- or four-digit.
     */
    private static function twoDigitYear(string $year): string
    {
        return strlen($year) === 4 ? substr($year, 2) : $year;
    }
}
