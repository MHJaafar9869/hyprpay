<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\AbstractPaymentGateway;
use Hyprpay\Payments\Domain\Command\CaptureRequest;
use Hyprpay\Payments\Domain\Command\ChargeRequest;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Gateway\LoggingGateway;

/**
 * @return array{0: LoggingGateway, 1: RecordingLogger}
 */
function loggingGateway(): array
{
    $logger = new RecordingLogger;

    return [new LoggingGateway(eventStubGateway(), $logger), $logger];
}

it('logs a charge as a timed operation with safe correlation context', function (): void {
    [$gateway, $logger] = loggingGateway();

    $result = $gateway->charge(new ChargeRequest(transientToken: 'tok', money: Money::minor(10000, 'USD'), orderReference: 'ORD-1'));

    expect($result)->toBeInstanceOf(PaymentResult::class)
        ->and($logger->records)->toHaveCount(1);

    ['level' => $level, 'message' => $message, 'context' => $context] = $logger->records[0];

    expect($level)->toBe('info')
        ->and($message)->toBe('[LoggingGateway] charge')
        ->and($context['gateway'])->toBe('paypal')
        ->and($context['order_reference'])->toBe('ORD-1')
        ->and($context['amount'])->toBe('100.00')
        ->and($context['currency'])->toBe('USD')
        ->and($context['duration_ms'])->toBeFloat()
        ->and($context['action'])->toContain('LoggingGateway');
});

it('logs a capture with the authorization transaction id', function (): void {
    [$gateway, $logger] = loggingGateway();

    $gateway->capture(new CaptureRequest(transactionId: 'AUTH-1', money: Money::minor(6000, 'USD'), orderReference: 'ORD-1'));

    expect($logger->records[0]['message'])->toBe('[LoggingGateway] capture')
        ->and($logger->records[0]['context']['transaction_id'])->toBe('AUTH-1');
});

it('passes name and credentials through without logging', function (): void {
    [$gateway, $logger] = loggingGateway();

    expect($gateway->name())->toBe(GatewayName::PayPal)
        ->and($gateway->credentials())->toBeInstanceOf(GatewayCredentials::class)
        ->and($logger->records)->toBeEmpty();
});

it('returns the inner result unchanged', function (): void {
    [$gateway] = loggingGateway();

    expect($gateway->charge(new ChargeRequest(transientToken: 'tok', money: Money::minor(10000, 'USD')))->transactionId)->toBe('CH-1');
});

it('logs the timing even when the operation throws, then propagates', function (): void {
    $inner = new class(testCredentials()) extends AbstractPaymentGateway
    {
        public function name(): GatewayName
        {
            return GatewayName::PayPal;
        }

        public function charge(ChargeRequest $request): PaymentResult
        {
            throw new GatewayRequestException(status: 500, responseBody: '');
        }
    };
    $logger = new RecordingLogger;
    $gateway = new LoggingGateway($inner, $logger);

    expect(fn (): PaymentResult => $gateway->charge(new ChargeRequest(transientToken: 'tok', money: Money::minor(1000, 'USD'))))
        ->toThrow(GatewayRequestException::class);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('[LoggingGateway] charge')
        ->and($logger->records[0]['context'])->toHaveKey('duration_ms');
});
