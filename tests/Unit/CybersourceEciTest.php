<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\ValueObject\CybersourceEci;

it('resolves the eci from a consumer-authentication block, preferring eciRaw', function (): void {
    expect(CybersourceEci::fromConsumerAuthentication(['eciRaw' => '02', 'eci' => '05'])?->value)->toBe('02')
        ->and(CybersourceEci::fromConsumerAuthentication(['eci' => '05'])?->value)->toBe('05')
        ->and(CybersourceEci::fromConsumerAuthentication([]))->toBeNull();
});

it('zero-pads a single-digit eci to two digits', function (): void {
    expect(CybersourceEci::fromRaw('5')?->value)->toBe('05')
        ->and(CybersourceEci::fromRaw(6)?->value)->toBe('06')
        ->and(CybersourceEci::fromRaw('')?->value)->toBeNull()
        ->and(CybersourceEci::fromRaw(null))->toBeNull();
});

it('classifies a fully authenticated eci for both card-network families', function (string $eci): void {
    $resolved = CybersourceEci::fromRaw($eci);

    expect($resolved?->isFullyAuthenticated())->toBeTrue()
        ->and($resolved?->isAttempted())->toBeFalse()
        ->and($resolved?->isNotAuthenticated())->toBeFalse()
        ->and($resolved?->outcome())->toBe('fully_authenticated');
})->with(['02', '05']);

it('classifies an attempted eci', function (string $eci): void {
    $resolved = CybersourceEci::fromRaw($eci);

    expect($resolved?->isFullyAuthenticated())->toBeFalse()
        ->and($resolved?->isAttempted())->toBeTrue()
        ->and($resolved?->outcome())->toBe('attempted');
})->with(['01', '06']);

it('classifies a not-authenticated eci', function (string $eci): void {
    $resolved = CybersourceEci::fromRaw($eci);

    expect($resolved?->isFullyAuthenticated())->toBeFalse()
        ->and($resolved?->isNotAuthenticated())->toBeTrue()
        ->and($resolved?->outcome())->toBe('not_authenticated');
})->with(['00', '07']);
