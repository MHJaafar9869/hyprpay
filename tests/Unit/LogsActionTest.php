<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Support\Concerns\LogsAction;
use Psr\Log\LoggerInterface;

/**
 * A minimal class exercising the LogsAction trait against an injected PSR-3 logger.
 */
final class LogsActionSubject
{
    use LogsAction;

    public function __construct(private LoggerInterface $psrLogger) {}

    protected function logger(): LoggerInterface
    {
        return $this->psrLogger;
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->logInfo($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->logError($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function timed(string $message, callable $callback, array $context = []): mixed
    {
        return $this->logTimedAction($message, $callback, $context);
    }
}

it('prefixes the message with the class short name and tags the context with the FQCN', function (): void {
    $logger = new RecordingLogger;

    (new LogsActionSubject($logger))->info('Charging order', ['order' => 'ORD-1']);

    ['level' => $level, 'message' => $message, 'context' => $context] = $logger->records[0];

    expect($level)->toBe('info')
        ->and($message)->toBe('[LogsActionSubject] Charging order')
        ->and($context['action'])->toBe(LogsActionSubject::class)
        ->and($context['order'])->toBe('ORD-1');
});

it('maps each helper to its PSR-3 level', function (): void {
    $logger = new RecordingLogger;

    (new LogsActionSubject($logger))->error('boom');

    expect($logger->records[0]['level'])->toBe('error')
        ->and($logger->records[0]['message'])->toBe('[LogsActionSubject] boom');
});

it('recursively masks sensitive keys and keeps the rest', function (): void {
    $logger = new RecordingLogger;

    (new LogsActionSubject($logger))->info('x', [
        'card_number' => '4111111111111111',
        'cvv' => '123',
        'amount' => '100.00',
        'nested' => ['token' => 'abc', 'shared_secret' => 'xyz', 'safe' => 'keep'],
    ]);

    $context = $logger->records[0]['context'];

    expect($context['card_number'])->toBe('********')
        ->and($context['cvv'])->toBe('********')
        ->and($context['amount'])->toBe('100.00')
        ->and($context['nested']['token'])->toBe('********')
        ->and($context['nested']['shared_secret'])->toBe('********')
        ->and($context['nested']['safe'])->toBe('keep');
});

it('runs, times, and returns the callback result', function (): void {
    $logger = new RecordingLogger;

    $result = (new LogsActionSubject($logger))->timed('Processing', fn (): string => 'done', ['order' => 'ORD-1']);

    ['message' => $message, 'context' => $context] = $logger->records[0];

    expect($result)->toBe('done')
        ->and($message)->toBe('[LogsActionSubject] Processing')
        ->and($context['order'])->toBe('ORD-1')
        ->and($context['duration_ms'])->toBeFloat();
});

it('still logs the timing when the callback throws, then rethrows', function (): void {
    $logger = new RecordingLogger;

    $call = fn (): mixed => (new LogsActionSubject($logger))->timed('Processing', function (): void {
        throw new RuntimeException('nope');
    });

    expect($call)->toThrow(RuntimeException::class);
    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context'])->toHaveKey('duration_ms');
});
