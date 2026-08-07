<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawryCheckoutOptions;

/**
 * Derives the shared FawryPay request fields from a CheckoutSessionRequest.
 *
 * Centralises the mapping of the SDK's gateway-agnostic checkout request onto the
 * fields common to FawryPay's hosted-init and charge payloads (merchant reference,
 * charge items, language, and customer details) so the individual payload builders
 * stay small and consistent.
 */
final class FawryFields
{
    /**
     * Merchant reference number FawryPay echoes back on callbacks and webhooks.
     */
    public static function merchantRefNum(CheckoutSessionRequest $request): string
    {
        return $request->orderReference ?? '';
    }

    /**
     * Identifier for the single derived charge item (falls back to a stable default).
     */
    public static function itemId(CheckoutSessionRequest $request): string
    {
        return filled($request->orderReference) ? $request->orderReference : 'item-1';
    }

    /**
     * Human-readable order description (falls back to a generic label).
     */
    public static function description(CheckoutSessionRequest $request): string
    {
        return filled($request->description) ? $request->description : 'Payment';
    }

    /**
     * FawryPay UI language, taken from the request locale or the credential default.
     */
    public static function language(CheckoutSessionRequest $request, GatewayCredentials $credentials): string
    {
        return $request->locale ?? $credentials->locale;
    }

    /**
     * Build the single-line charge-items array for the given formatted amount.
     *
     * @return array<int, array{itemId: string, description: string, price: string, quantity: string}>
     */
    public static function chargeItems(CheckoutSessionRequest $request, string $amount): array
    {
        return [[
            'itemId' => self::itemId($request),
            'description' => self::description($request),
            'price' => $amount,
            'quantity' => '1',
        ]];
    }

    /**
     * Optional merchant customer id (customerProfileId).
     */
    public static function customerProfileId(CheckoutSessionRequest $request): ?string
    {
        return $request->customer?->reference;
    }

    /**
     * Customer email, from the customer profile or the options bag.
     */
    public static function customerEmail(CheckoutSessionRequest $request): ?string
    {
        $customerEmail = $request->customer?->email;

        return $customerEmail ?? FawryCheckoutOptions::fromRequest($request)->customerEmail;
    }

    /**
     * Customer mobile number, taken from the checkout options.
     */
    public static function customerMobile(CheckoutSessionRequest $request): ?string
    {
        return FawryCheckoutOptions::fromRequest($request)->customerMobile;
    }

    /**
     * Customer full name assembled from the customer profile, or null when absent.
     */
    public static function customerName(CheckoutSessionRequest $request): ?string
    {
        $firstName = $request->customer?->firstName;
        $lastName = $request->customer?->lastName;
        $name = trim(($firstName ?? '').' '.($lastName ?? ''));

        return $name !== '' ? $name : null;
    }
}
