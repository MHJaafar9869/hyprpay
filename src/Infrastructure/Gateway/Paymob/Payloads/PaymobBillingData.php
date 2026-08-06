<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Builds the Paymob `billing_data` block for the payment-key request.
 *
 * Paymob rejects payment-key requests with missing billing fields, so every field
 * is always present and any value the caller did not supply (from the customer,
 * billing address, or options bag) falls back to the literal "NA" placeholder.
 */
final class PaymobBillingData
{
    /**
     * @return array<string, string>
     */
    public static function fromRequest(CheckoutSessionRequest $request): array
    {
        $billTo = $request->billTo;
        $customer = $request->customer;
        $customerFirstName = $customer?->firstName;
        $customerLastName = $customer?->lastName;
        $customerEmail = $customer?->email;

        return [
            'first_name' => self::value($customerFirstName ?? $billTo?->firstName),
            'last_name' => self::value($customerLastName ?? $billTo?->lastName),
            'email' => self::value($customerEmail ?? $billTo?->email),
            'phone_number' => self::value($request->options['customer_mobile'] ?? $billTo?->phoneNumber),
            'apartment' => self::value($billTo?->address2),
            'floor' => 'NA',
            'street' => self::value($billTo?->address1),
            'building' => 'NA',
            'shipping_method' => 'NA',
            'postal_code' => self::value($billTo?->postalCode),
            'city' => self::value($billTo?->locality),
            'country' => self::value($billTo?->country),
            'state' => self::value($billTo?->administrativeArea),
        ];
    }

    /**
     * Return the trimmed value, or Paymob's "NA" placeholder when it is blank.
     *
     * @param  mixed  $value
     */
    private static function value($value): string
    {
        return filled($value) ? Value::string($value) : 'NA';
    }
}
