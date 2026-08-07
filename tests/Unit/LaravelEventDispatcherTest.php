<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Event\PaymentEvent;
use Hyprpay\Payments\Domain\Event\WebhookReceived;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Infrastructure\Events\LaravelEventDispatcher;
use Illuminate\Events\Dispatcher;

it('forwards a payment event to a listener registered on the concrete class', function (): void {
    $laravel = new Dispatcher;
    $received = [];
    $laravel->listen(WebhookReceived::class, function (WebhookReceived $event) use (&$received): void {
        $received[] = $event;
    });

    (new LaravelEventDispatcher($laravel))->dispatch(new WebhookReceived(GatewayName::PayPal, new WebhookEvent(true)));

    expect($received)->toHaveCount(1)
        ->and($received[0]->gateway())->toBe(GatewayName::PayPal);
});

it('routes to a single listener registered on the PaymentEvent interface', function (): void {
    $laravel = new Dispatcher;
    $received = [];
    $laravel->listen(PaymentEvent::class, function (PaymentEvent $event) use (&$received): void {
        $received[] = $event;
    });

    (new LaravelEventDispatcher($laravel))->dispatch(new WebhookReceived(GatewayName::Paytabs, new WebhookEvent(false)));

    expect($received)->toHaveCount(1)
        ->and($received[0])->toBeInstanceOf(WebhookReceived::class);
});
