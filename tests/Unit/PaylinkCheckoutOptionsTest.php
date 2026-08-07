<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\Paylink\PaylinkCheckoutOptions;

it('maps a legacy options array onto typed fields', function (): void {
    $options = PaylinkCheckoutOptions::fromArray([
        'webhook_url' => 'https://shop.test/webhook',
        'order_details' => 'Gold Plan',
        'payment_mode' => 'test',
        'iframe' => true,
        'idempotency_key' => 'KEY-1',
    ]);

    expect($options->webhookUrl)->toBe('https://shop.test/webhook')
        ->and($options->orderDetails)->toBe('Gold Plan')
        ->and($options->paymentMode)->toBe('test')
        ->and($options->iframe)->toBeTrue()
        ->and($options->idempotencyKey)->toBe('KEY-1');
});

it('coerces a truthy iframe string to a bool', function (): void {
    expect(PaylinkCheckoutOptions::fromArray(['iframe' => '1'])->iframe)->toBeTrue()
        ->and(PaylinkCheckoutOptions::fromArray([])->iframe)->toBeFalse();
});

it('renders only the fields that are set, dropping a false iframe', function (): void {
    expect((new PaylinkCheckoutOptions(webhookUrl: 'https://shop.test/webhook'))->toArray())
        ->toBe(['webhook_url' => 'https://shop.test/webhook']);
});
