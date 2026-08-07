<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\Paymob\PaymobCheckoutOptions;

it('maps a legacy options array onto typed fields', function (): void {
    $options = PaymobCheckoutOptions::fromArray([
        'integration_id' => 555,
        'iframe_id' => '777',
        'expiration' => 600,
        'customer_mobile' => '01000000000',
    ]);

    expect($options->integrationId)->toBe(555)
        ->and($options->iframeId)->toBe('777')
        ->and($options->expiration)->toBe(600)
        ->and($options->customerMobile)->toBe('01000000000');
});

it('renders only the fields that are set', function (): void {
    expect((new PaymobCheckoutOptions(integrationId: 555, expiration: 600))->toArray())
        ->toBe(['integration_id' => 555, 'expiration' => 600]);
});

it('keeps an integer id as an int and drops a blank one', function (): void {
    expect(PaymobCheckoutOptions::fromArray(['integration_id' => 555])->integrationId)->toBe(555)
        ->and(PaymobCheckoutOptions::fromArray(['integration_id' => ''])->integrationId)->toBeNull();
});
