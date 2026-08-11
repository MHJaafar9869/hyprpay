<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Payloads\DeviceInformation;

it('returns an empty block when no fingerprint session id is set', function (): void {
    expect(DeviceInformation::fields(null))->toBe([])
        ->and(DeviceInformation::fields(''))->toBe([])
        ->and(DeviceInformation::fields(null, true))->toBe([]);
});

it('emits the fingerprint session id alone by default', function (): void {
    expect(DeviceInformation::fields('sess_123'))->toBe(['fingerprintSessionId' => 'sess_123']);
});

it('adds the raw flag only when requested', function (): void {
    expect(DeviceInformation::fields('sess_123', true))
        ->toBe(['fingerprintSessionId' => 'sess_123', 'useRawFingerprintSessionId' => true]);
});
