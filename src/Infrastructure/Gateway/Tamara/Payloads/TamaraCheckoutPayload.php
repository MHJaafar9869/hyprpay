<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Tamara\Payloads;

use Hyprpay\Payments\Domain\Command\CheckoutSessionRequest;
use Hyprpay\Payments\Domain\ValueObject\BillingAddress;
use Hyprpay\Payments\Domain\ValueObject\Customer;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Builds the POST /checkout request body that starts a Tamara hosted checkout session.
 *
 * Maps the SDK's CheckoutSessionRequest onto Tamara's order shape: the total amount,
 * merchant references, a single line item covering the order, the consumer and address
 * blocks (when provided), the merchant redirect URLs, and the payment plan resolved from
 * the request or the gateway's configured default.
 */
final class TamaraCheckoutPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(CheckoutSessionRequest $request, GatewayCredentials $credentials): array
    {
        $money = $request->money;
        $reference = $request->orderReference ?? '';

        $body = [
            'total_amount' => TamaraMoney::of($money),
            'order_reference_id' => $reference,
            'order_number' => $reference,
            'country_code' => $request->country ?? $credentials->country,
            'locale' => $request->locale ?? $credentials->locale,
            'payment_type' => Value::string($credentials->extra('payment_type'), 'PAY_BY_INSTALMENTS'),
            'description' => filled($request->description) ? $request->description : 'Payment',
            'items' => [self::item($request, $money)],
            'merchant_url' => self::merchantUrls($request),
        ];

        $instalments = Value::int($credentials->extra('instalments'));

        if ($instalments > 0) {
            $body['instalments'] = $instalments;
        }

        $consumer = self::consumer($request);

        if ($consumer !== []) {
            $body['consumer'] = $consumer;
        }

        if ($request->billTo instanceof BillingAddress && ! $request->billTo->isEmpty()) {
            $address = self::address($request->billTo);
            $body['billing_address'] = $address;
            $body['shipping_address'] = $address;
        }

        return $body;
    }

    /**
     * Build the single line item that represents the whole order.
     *
     * @return array<string, mixed>
     */
    private static function item(CheckoutSessionRequest $request, Money $money): array
    {
        $reference = $request->orderReference ?? 'item';

        return [
            'name' => filled($request->description) ? $request->description : 'Order '.$reference,
            'type' => 'Digital',
            'reference_id' => $reference,
            'sku' => $reference,
            'quantity' => 1,
            'unit_price' => TamaraMoney::of($money),
            'total_amount' => TamaraMoney::of($money),
            'discount_amount' => TamaraMoney::zero($money),
            'tax_amount' => TamaraMoney::zero($money),
        ];
    }

    /**
     * Build the merchant redirect URLs Tamara returns the customer to.
     *
     * @return array<string, string>
     */
    private static function merchantUrls(CheckoutSessionRequest $request): array
    {
        $return = $request->returnUrl ?? '';

        return ['success' => $return, 'failure' => $return, 'cancel' => $return];
    }

    /**
     * Build the consumer block from the customer and billing details, including only present fields.
     *
     * @return array<string, string>
     */
    private static function consumer(CheckoutSessionRequest $request): array
    {
        $consumer = [];
        $customer = $request->customer;
        $billTo = $request->billTo;

        if ($customer instanceof Customer) {
            $consumer = array_filter([
                'email' => Value::nullableString($customer->email),
                'first_name' => Value::nullableString($customer->firstName),
                'last_name' => Value::nullableString($customer->lastName),
            ], fn (?string $value): bool => $value !== null);
        }

        if ($billTo instanceof BillingAddress) {
            $consumer += array_filter([
                'email' => Value::nullableString($billTo->email),
                'first_name' => Value::nullableString($billTo->firstName),
                'last_name' => Value::nullableString($billTo->lastName),
                'phone_number' => Value::nullableString($billTo->phoneNumber),
            ], fn (?string $value): bool => $value !== null);
        }

        return $consumer;
    }

    /**
     * Build Tamara's address block from a billing address, including only present fields.
     *
     * @return array<string, string>
     */
    private static function address(BillingAddress $address): array
    {
        return array_filter([
            'first_name' => Value::nullableString($address->firstName),
            'last_name' => Value::nullableString($address->lastName),
            'line1' => Value::nullableString($address->address1),
            'line2' => Value::nullableString($address->address2),
            'city' => Value::nullableString($address->locality),
            'region' => Value::nullableString($address->administrativeArea),
            'phone_number' => Value::nullableString($address->phoneNumber),
            'country_code' => Value::nullableString($address->country),
        ], fn (?string $value): bool => $value !== null);
    }
}
