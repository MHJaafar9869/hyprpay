<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Event\PaymentCaptured;
use Hyprpay\Payments\Domain\Event\PaymentRefunded;
use Hyprpay\Payments\Domain\Event\WebhookReceived;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\RefundResult;
use Hyprpay\Payments\Domain\Result\WebhookEvent;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Events\LoggingPaymentEventListener;

it('logs a redaction-safe audit line at info level for a capture', function (): void {
    $logger = new RecordingLogger;

    (new LoggingPaymentEventListener($logger))->handle(new PaymentCaptured(
        GatewayName::PayPal,
        'AUTH-1',
        'ORD-1',
        Money::minor(10000, 'USD'),
        new PaymentResult(true, PaymentStatus::Captured, 'CAP-1', raw: ['card' => ['number' => '4111111111111111']]),
    ));

    expect($logger->records)->toHaveCount(1);
    ['level' => $level, 'message' => $message, 'context' => $context] = $logger->records[0];

    expect($level)->toBe('info')
        ->and($message)->toBe('gateway.payment.event')
        ->and($context['event'])->toBe('PaymentCaptured')
        ->and($context['gateway'])->toBe('paypal')
        ->and($context['authorization'])->toBe('AUTH-1')
        ->and($context['order'])->toBe('ORD-1')
        ->and($context['transaction'])->toBe('CAP-1')
        ->and($context['status'])->toBe('captured')
        ->and($context['success'])->toBeTrue();
});

it('never logs the raw payload or card data', function (): void {
    $logger = new RecordingLogger;

    (new LoggingPaymentEventListener($logger))->handle(new PaymentCaptured(
        GatewayName::PayPal,
        'AUTH-1',
        'ORD-1',
        Money::minor(10000, 'USD'),
        new PaymentResult(true, PaymentStatus::Captured, 'CAP-1', raw: ['pan' => '4111111111111111']),
    ));

    expect($logger->records[0]['context'])->not->toHaveKey('raw')
        ->and(json_encode($logger->records[0]['context']))->not->toContain('4111111111111111');
});

it('logs the refund id for a refund event', function (): void {
    $logger = new RecordingLogger;

    (new LoggingPaymentEventListener($logger))->handle(new PaymentRefunded(
        GatewayName::Paytabs,
        'CAP-1',
        'ORD-1',
        Money::minor(2500, 'SAR'),
        new RefundResult(true, PaymentStatus::Refunded, 'REF-1'),
    ));

    expect($logger->records[0]['context']['refund'])->toBe('REF-1')
        ->and($logger->records[0]['context']['status'])->toBe('refunded')
        ->and($logger->records[0]['context']['gateway'])->toBe('paytabs');
});

it('logs the verification flag for a webhook event', function (): void {
    $logger = new RecordingLogger;

    (new LoggingPaymentEventListener($logger))->handle(new WebhookReceived(
        GatewayName::PayPal,
        new WebhookEvent(true, 'PAYMENT.CAPTURE.COMPLETED', 'WT-1', PaymentStatus::Captured),
    ));

    $context = $logger->records[0]['context'];
    expect($context['event'])->toBe('WebhookReceived')
        ->and($context['verified'])->toBeTrue()
        ->and($context['transaction'])->toBe('WT-1')
        ->and($context['status'])->toBe('captured');
});
