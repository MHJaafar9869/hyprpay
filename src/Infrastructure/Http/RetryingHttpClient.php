<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Http;

use Closure;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Throwable;

/**
 * HttpClient decorator that retries transient failures with exponential backoff.
 *
 * Wraps any {@see HttpClient} and re-sends the request when the inner client returns
 * a retryable status (by default 408, 429, and 5xx) or throws one of the configured
 * retryable exceptions (e.g. a connection timeout). Because the SDK builds requests
 * deterministically and carries idempotency keys, replaying a failed write is safe —
 * the gateway deduplicates it. Backoff doubles each attempt (`baseDelayMs * 2^(n-1)`);
 * the sleep function is injectable so tests run without real delay.
 */
final readonly class RetryingHttpClient implements HttpClient
{
    /**
     * @var Closure(int): void
     */
    private Closure $sleeper;

    /**
     * @param  HttpClient  $inner  The wrapped client that performs the actual request.
     * @param  int  $maxAttempts  Total attempts including the first (e.g. 3 = 1 try + 2 retries).
     * @param  int  $baseDelayMs  Base backoff delay in milliseconds, doubled each retry.
     * @param  array<int, int>  $retryableStatuses  HTTP statuses that trigger a retry.
     * @param  array<int, class-string<Throwable>>  $retryableExceptions  Exception types that trigger a retry.
     * @param  (Closure(int): void)|null  $sleeper  Sleep function (microseconds); defaults to usleep().
     */
    public function __construct(
        private HttpClient $inner,
        private int $maxAttempts = 3,
        private int $baseDelayMs = 200,
        private array $retryableStatuses = [408, 429, 500, 502, 503, 504],
        private array $retryableExceptions = [],
        ?Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * Send the request, retrying transient failures until it succeeds or attempts run out.
     */
    public function send(HttpRequest $request): HttpResponse
    {
        $attempt = 1;

        while (true) {
            try {
                $response = $this->inner->send($request);
            } catch (Throwable $exception) {
                if ($attempt >= $this->maxAttempts || ! $this->shouldRetryException($exception)) {
                    throw $exception;
                }

                $this->backoff($attempt);
                $attempt++;

                continue;
            }

            if ($attempt >= $this->maxAttempts || ! $this->isRetryableStatus($response->status)) {
                return $response;
            }

            $this->backoff($attempt);
            $attempt++;
        }
    }

    private function isRetryableStatus(int $status): bool
    {
        return in_array($status, $this->retryableStatuses, true);
    }

    private function shouldRetryException(Throwable $exception): bool
    {
        foreach ($this->retryableExceptions as $retryable) {
            if ($exception instanceof $retryable) {
                return true;
            }
        }

        return false;
    }

    private function backoff(int $attempt): void
    {
        $delayMs = $this->baseDelayMs * (2 ** ($attempt - 1));

        ($this->sleeper)($delayMs * 1000);
    }
}
