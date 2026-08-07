<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalPaymentMethodPreference;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalShippingPreference;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalUserAction;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\PayPalCheckoutOptions;

it('renders only the set fields, mapping enums to their PayPal values', function (): void {
    $options = new PayPalCheckoutOptions(
        cancelUrl: 'https://shop.test/cancel',
        brandName: 'Example Store',
        shippingPreference: PayPalShippingPreference::NoShipping,
        userAction: PayPalUserAction::PayNow,
    );

    expect($options->toArray())->toBe([
        'cancel_url' => 'https://shop.test/cancel',
        'brand_name' => 'Example Store',
        'shipping_preference' => 'NO_SHIPPING',
        'user_action' => 'PAY_NOW',
    ]);
});

it('returns an empty array when no options are set', function (): void {
    expect((new PayPalCheckoutOptions)->toArray())->toBe([]);
});

it('maps a legacy options array onto typed fields', function (): void {
    $options = PayPalCheckoutOptions::fromArray([
        'cancel_url' => 'https://shop.test/cancel',
        'brand_name' => 'Example Store',
        'locale' => 'en-GB',
        'shipping_preference' => 'SET_PROVIDED_ADDRESS',
        'user_action' => 'CONTINUE',
        'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
    ]);

    expect($options->cancelUrl)->toBe('https://shop.test/cancel')
        ->and($options->brandName)->toBe('Example Store')
        ->and($options->locale)->toBe('en-GB')
        ->and($options->shippingPreference)->toBe(PayPalShippingPreference::SetProvidedAddress)
        ->and($options->userAction)->toBe(PayPalUserAction::Continue_)
        ->and($options->paymentMethodPreference)->toBe(PayPalPaymentMethodPreference::ImmediatePaymentRequired);
});

it('drops unknown enum values from a legacy options array', function (): void {
    $options = PayPalCheckoutOptions::fromArray([
        'brand_name' => 'Example Store',
        'user_action' => 'NOT_A_REAL_ACTION',
    ]);

    expect($options->brandName)->toBe('Example Store')
        ->and($options->userAction)->toBeNull();
});

it('round-trips a legacy array through fromArray and toArray', function (): void {
    $raw = [
        'cancel_url' => 'https://shop.test/cancel',
        'brand_name' => 'Example Store',
        'shipping_preference' => 'GET_FROM_FILE',
        'user_action' => 'PAY_NOW',
    ];

    expect(PayPalCheckoutOptions::fromArray($raw)->toArray())->toBe($raw);
});
