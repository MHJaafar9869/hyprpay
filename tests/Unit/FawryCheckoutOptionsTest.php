<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawryCard;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawryCheckoutOptions;

it('maps a legacy options array onto typed fields, including the nested card', function (): void {
    $options = FawryCheckoutOptions::fromArray([
        'card' => ['number' => '4111111111111111', 'expiryYear' => '30', 'expiryMonth' => '12', 'cvv' => '123'],
        'wallet_number' => '01000000000',
        'installment_plan_id' => 'PLAN-12',
        'customer_email' => 'ada@shop.test',
        'customer_mobile' => '01099999999',
        'webhook_url' => 'https://shop.test/webhook',
    ]);

    expect($options->card)->toBeInstanceOf(FawryCard::class)
        ->and($options->card?->number)->toBe('4111111111111111')
        ->and($options->card?->cvv)->toBe('123')
        ->and($options->walletNumber)->toBe('01000000000')
        ->and($options->installmentPlanId)->toBe('PLAN-12')
        ->and($options->customerEmail)->toBe('ada@shop.test')
        ->and($options->customerMobile)->toBe('01099999999')
        ->and($options->webhookUrl)->toBe('https://shop.test/webhook');
});

it('leaves the card null when no card option is present', function (): void {
    expect(FawryCheckoutOptions::fromArray(['wallet_number' => '01000000000'])->card)->toBeNull();
});

it('round-trips through fromArray and toArray', function (): void {
    $raw = [
        'card' => ['number' => '4111111111111111', 'expiryYear' => '30', 'expiryMonth' => '12', 'cvv' => '123'],
        'wallet_number' => '01000000000',
    ];

    expect(FawryCheckoutOptions::fromArray($raw)->toArray())->toBe($raw);
});

it('renders only the fields that are set', function (): void {
    expect((new FawryCheckoutOptions(webhookUrl: 'https://shop.test/webhook'))->toArray())
        ->toBe(['webhook_url' => 'https://shop.test/webhook']);
});
