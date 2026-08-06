<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\ValueObject\Money;

it('renders minor units as an exact decimal string without rounding', function (int $minor, string $currency, int $scale, string $expected): void {
    expect((new Money($minor, $currency, $scale))->toDecimalString())->toBe($expected);
})->with([
    'two decimals whole' => [10000, 'EGP', 2, '100.00'],
    'two decimals cents' => [199, 'USD', 2, '1.99'],
    'single cent' => [1, 'USD', 2, '0.01'],
    'three decimals (KWD)' => [1000, 'KWD', 3, '1.000'],
    'zero decimals (JPY)' => [1000, 'JPY', 0, '1000'],
    'negative amount' => [-2550, 'USD', 2, '-25.50'],
    'large value keeps precision' => [999999999, 'USD', 2, '9999999.99'],
]);

it('uppercases the currency via the minor() constructor', function (): void {
    expect(Money::minor(500, 'egp')->currency)->toBe('EGP');
});

it('rejects a negative scale', function (): void {
    expect(fn (): Money => new Money(100, 'USD', -1))->toThrow(InvalidArgumentException::class);
});
