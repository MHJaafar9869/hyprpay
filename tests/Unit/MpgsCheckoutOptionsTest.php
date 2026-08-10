<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\MpgsCheckoutOptions;

it('maps the options from a raw array', function (): void {
    $options = MpgsCheckoutOptions::fromArray([
        'operation' => 'VERIFY',
        'merchant_name' => 'JK Enterprises LLC',
        'merchant_url' => 'https://shop.test',
        'checkout_mode' => 'PAYMENT_LINK',
    ]);

    expect($options->operation)->toBe('VERIFY')
        ->and($options->merchantName)->toBe('JK Enterprises LLC')
        ->and($options->merchantUrl)->toBe('https://shop.test')
        ->and($options->checkoutMode)->toBe('PAYMENT_LINK')
        ->and($options->returnUrl)->toBeNull();
});

it('renders only the set fields through toArray', function (): void {
    expect((new MpgsCheckoutOptions(operation: 'AUTHORIZE', returnUrl: 'https://shop.test/return'))->toArray())
        ->toBe(['operation' => 'AUTHORIZE', 'return_url' => 'https://shop.test/return']);
});
