<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Domain\Contract\PaymentGatewayInterface;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Exception\GatewayNotSupportedException;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

it('builds the CyberSource driver from explicit credentials without touching the resolver', function (): void {
    $resolver = recordingResolver(testCredentials());
    $factory = new PaymentGatewayFactory(new FakeHttpClient, $resolver);

    $gateway = $factory->make(GatewayName::CybersourceUnifiedCheckout, testCredentials());

    expect($gateway)->toBeInstanceOf(CybersourceUnifiedCheckoutGateway::class)
        ->and($resolver->called)->toBeFalse();
});

it('falls back to the resolver when no credentials are supplied', function (): void {
    $resolver = recordingResolver(testCredentials());
    $factory = new PaymentGatewayFactory(new FakeHttpClient, $resolver);

    $factory->make(GatewayName::CybersourceUnifiedCheckout);

    expect($resolver->called)->toBeTrue();
});

it('resolves a driver by its string name', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(testCredentials()));

    expect($factory->makeByName('cybersource_uc', testCredentials()))
        ->toBeInstanceOf(CybersourceUnifiedCheckoutGateway::class);
});

it('throws for an unknown gateway name', function (): void {
    $factory = new PaymentGatewayFactory(new FakeHttpClient, recordingResolver(testCredentials()));

    expect(fn (): PaymentGatewayInterface => $factory->makeByName('paypal'))->toThrow(GatewayNotSupportedException::class);
});
