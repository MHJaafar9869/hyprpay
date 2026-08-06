<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Exception\MissingCredentialsException;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Credentials\ConfigCredentialResolver;
use Hyprpay\Payments\Tests\Support\ArrayConfig;

/**
 * @param  array<string, mixed>  $cybersource
 */
function resolverForConfig(array $cybersource): ConfigCredentialResolver
{
    return new ConfigCredentialResolver(new ArrayConfig([
        'gateway' => ['gateways' => ['cybersource_uc' => $cybersource]],
    ]));
}

it('resolves complete credentials and picks the test host in test mode', function (): void {
    $credentials = resolverForConfig([
        'test_mode' => true,
        'test_host' => 'apitest.cybersource.com',
        'live_host' => 'api.cybersource.com',
        'merchant_id' => 'm1',
        'api_key_id' => 'k1',
        'shared_secret' => base64_encode('s1'),
    ])->resolve(GatewayName::CybersourceUnifiedCheckout);

    expect($credentials->host)->toBe('apitest.cybersource.com')
        ->and($credentials->merchantId)->toBe('m1')
        ->and($credentials->testMode)->toBeTrue();
});

it('selects the live host when test mode is disabled', function (): void {
    $credentials = resolverForConfig([
        'test_mode' => false,
        'test_host' => 'apitest.cybersource.com',
        'live_host' => 'api.cybersource.com',
        'merchant_id' => 'm1',
        'api_key_id' => 'k1',
        'shared_secret' => base64_encode('s1'),
    ])->resolve(GatewayName::CybersourceUnifiedCheckout);

    expect($credentials->host)->toBe('api.cybersource.com');
});

it('throws when the credentials are incomplete', function (): void {
    expect(fn (): GatewayCredentials => resolverForConfig(['merchant_id' => 'm1'])->resolve(GatewayName::CybersourceUnifiedCheckout))
        ->toThrow(MissingCredentialsException::class);
});

it('throws when no configuration exists for the gateway', function (): void {
    $resolver = new ConfigCredentialResolver(new ArrayConfig([]));

    expect(fn (): GatewayCredentials => $resolver->resolve(GatewayName::CybersourceUnifiedCheckout))
        ->toThrow(MissingCredentialsException::class);
});
