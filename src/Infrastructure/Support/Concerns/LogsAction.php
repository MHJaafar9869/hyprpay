<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Support\Concerns;

use Psr\Log\LoggerInterface;

/**
 * Adds level-based, self-identifying logging to a class through an injected PSR-3 logger.
 *
 * Every message is prefixed with the class's short name (`[ClassName] message`), and every
 * context array is tagged with the fully-qualified class under `action` and recursively
 * masked so sensitive keys (card number, cvv, tokens, secrets, …) never reach the log.
 * {@see logTimedAction()} additionally records how long a callback took under `duration_ms`.
 *
 * Framework-agnostic by design: the consuming class supplies the destination via
 * {@see logger()}, so the trait needs no facade, container, or request context.
 */
trait LogsAction
{
    /**
     * The PSR-3 logger this trait writes to.
     */
    abstract protected function logger(): LoggerInterface;

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logDebug(string $message, array $context = []): void
    {
        $this->executeLogging('debug', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logInfo(string $message, array $context = []): void
    {
        $this->executeLogging('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logWarning(string $message, array $context = []): void
    {
        $this->executeLogging('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logError(string $message, array $context = []): void
    {
        $this->executeLogging('error', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logCritical(string $message, array $context = []): void
    {
        $this->executeLogging('critical', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logAlert(string $message, array $context = []): void
    {
        $this->executeLogging('alert', $message, $context);
    }

    /**
     * Run a callback, then log the message with how long it took (`duration_ms`).
     *
     * The timing is logged whether the callback returns or throws — on a throw the log is
     * still written and the exception propagates unchanged.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  array<string, mixed>  $context
     * @return T
     */
    protected function logTimedAction(string $message, callable $callback, array $context = []): mixed
    {
        $start = microtime(true);

        try {
            return $callback();
        } finally {
            $duration = round((microtime(true) - $start) * 1000, 2);
            $this->logInfo($message, array_merge($context, ['duration_ms' => $duration]));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function executeLogging(string $level, string $message, array $context): void
    {
        $this->logger()->log($level, $this->buildLogMessage($message), $this->buildLogContext($context));
    }

    private function buildLogMessage(string $message): string
    {
        return sprintf('[%s] %s', class_basename(static::class), $message);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function buildLogContext(array $context): array
    {
        return $this->maskSensitiveData(array_merge(['action' => static::class], $context));
    }

    /**
     * Recursively replace the value of any sensitive key with a masked placeholder.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function maskSensitiveData(array $data): array
    {
        $sensitiveKeys = [
            'password', 'password_confirmation', 'cvv', 'card_number', 'pan',
            'token', 'secret', 'key', 'api_key', 'shared_secret', 'authorization',
        ];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);

                continue;
            }

            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $data[$key] = '********';
            }
        }

        return $data;
    }
}
