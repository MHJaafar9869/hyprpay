<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Http;

use Closure;
use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;

/**
 * HttpClient decorator that throttles outbound requests with a token bucket.
 *
 * Wraps any {@see HttpClient} and admits at most `maxRequests` calls per `perSeconds`
 * window, smoothing bursts so the SDK stays under a gateway's published rate limit
 * instead of provoking (and then having to retry) HTTP 429s. The bucket starts full,
 * so an initial burst of up to `maxRequests` passes without delay; thereafter tokens
 * refill continuously at `maxRequests / perSeconds` per second. When the bucket is
 * empty the call blocks just long enough for one token to accrue.
 *
 * Place this decorator BELOW {@see RetryingHttpClient} so every real attempt — including
 * each retry — consumes a token. The limit is per process (one bucket per instance),
 * which is the right scope for client-side courtesy throttling; it is not a distributed
 * limiter. The clock and sleep function are injectable so tests run without real delay.
 */
final class RateLimitingHttpClient implements HttpClient
{
    /**
     * Maximum tokens the bucket can hold — the largest burst allowed before throttling.
     */
    private readonly float $capacity;

    /**
     * Tokens replenished per second (`maxRequests / perSeconds`).
     */
    private readonly float $refillRate;

    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;

    /**
     * @var Closure(int): void
     */
    private readonly Closure $sleeper;

    /**
     * Tokens currently available; one is consumed per request.
     */
    private float $tokens;

    /**
     * Monotonic timestamp (seconds) the bucket was last refilled.
     */
    private float $lastTick;

    /**
     * @param  HttpClient  $inner  The wrapped client that performs the actual request.
     * @param  int  $maxRequests  Requests permitted per window; also the burst capacity (clamped to >= 1).
     * @param  int  $perSeconds  Length of the refill window in seconds (clamped to >= 1).
     * @param  (Closure(): float)|null  $clock  Monotonic clock returning seconds; defaults to hrtime().
     * @param  (Closure(int): void)|null  $sleeper  Sleep function (microseconds); defaults to usleep().
     */
    public function __construct(
        private readonly HttpClient $inner,
        int $maxRequests = 10,
        int $perSeconds = 1,
        ?Closure $clock = null,
        ?Closure $sleeper = null,
    ) {
        $this->capacity = (float) max(1, $maxRequests);
        $this->refillRate = $this->capacity / (float) max(1, $perSeconds);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };

        $this->tokens = $this->capacity;
        $this->lastTick = ($this->clock)();
    }

    /**
     * Wait until a token is available, consume it, then delegate to the inner client.
     */
    public function send(HttpRequest $request): HttpResponse
    {
        $this->refill();

        if ($this->tokens < 1.0) {
            $waitSeconds = (1.0 - $this->tokens) / $this->refillRate;

            ($this->sleeper)((int) ceil($waitSeconds * 1_000_000));

            // Credit the token the wait just earned and advance the clock by exactly
            // that wait, so the next refill() never double-counts the time we slept.
            $this->tokens = 1.0;
            $this->lastTick += $waitSeconds;
        }

        $this->tokens -= 1.0;

        return $this->inner->send($request);
    }

    /**
     * Add the tokens accrued since the last tick, capped at the bucket capacity.
     */
    private function refill(): void
    {
        $now = ($this->clock)();
        $elapsed = $now - $this->lastTick;

        if ($elapsed <= 0.0) {
            return;
        }

        $this->tokens = min($this->capacity, $this->tokens + $elapsed * $this->refillRate);
        $this->lastTick = $now;
    }
}
