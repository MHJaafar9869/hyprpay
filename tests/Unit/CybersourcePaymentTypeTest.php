<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\CybersourcePaymentType;

it('covers the Unified Checkout launching payment types', function (): void {
    expect(CybersourcePaymentType::allValues())
        ->toBe(['PANENTRY', 'GOOGLEPAY', 'APPLEPAY', 'CLICKTOPAY', 'CHECK', 'PAZE']);
});

it('exposes the newly added eCheck and Paze cases', function (): void {
    expect(CybersourcePaymentType::ECheck->value)->toBe('CHECK')
        ->and(CybersourcePaymentType::Paze->value)->toBe('PAZE');
});
