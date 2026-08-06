<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;
use Hyprpay\Payments\Infrastructure\Http\RetryingHttpClient;

/**
 * Inner HttpClient double that throws a given exception for the first N calls,
 * then returns a fixed response. Used to exercise the retry-on-exception path.
 */
final class ThrowingHttpClient implements HttpClient
{
    private int $calls = 0;

    /**
     * @param  Throwable  $exception  The exception thrown on the first $failures calls.
     * @param  int  $failures  How many leading calls should throw before succeeding.
     * @param  HttpResponse  $response  The response returned once the throwing calls are exhausted.
     */
    public function __construct(
        private readonly Throwable $exception,
        private readonly int $failures,
        private readonly HttpResponse $response,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $this->calls++;

        if ($this->calls <= $this->failures) {
            throw $this->exception;
        }

        return $this->response;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}

/**
 * Marker exception configured as retryable in the exception-retry tests.
 */
final class RetryableTransportException extends RuntimeException {}

/**
 * Build a RetryingHttpClient around $inner with a no-op sleeper so tests never wait.
 *
 * @param  array<int, class-string<Throwable>>  $retryableExceptions
 */
function retrying(
    HttpClient $inner,
    int $maxAttempts = 3,
    int $baseDelayMs = 200,
    array $retryableExceptions = [],
    ?Closure $sleeper = null,
): RetryingHttpClient {
    return new RetryingHttpClient(
        $inner,
        $maxAttempts,
        $baseDelayMs,
        retryableExceptions: $retryableExceptions,
        sleeper: $sleeper ?? static fn (int $microseconds): null => null,
    );
}

function retryRequest(): HttpRequest
{
    return new HttpRequest('POST', 'https://gateway.test/charge');
}

it('retries a retryable status then succeeds', function (): void {
    $fake = (new FakeHttpClient)
        ->queue(new HttpResponse(503, '{}'))
        ->queue(new HttpResponse(200, '{"ok":true}'));

    $response = retrying($fake, maxAttempts: 3)->send(retryRequest());

    expect($response->status)->toBe(200)
        ->and($fake->requestCount())->toBe(2);
});

it('returns the last response after exhausting all attempts on a retryable status', function (): void {
    $fake = (new FakeHttpClient)
        ->queue(new HttpResponse(500, '{}'))
        ->queue(new HttpResponse(500, '{}'))
        ->queue(new HttpResponse(500, '{}'));

    $response = retrying($fake, maxAttempts: 3)->send(retryRequest());

    expect($response->status)->toBe(500)
        ->and($fake->requestCount())->toBe(3);
});

it('does not retry a non-retryable status', function (): void {
    $fake = (new FakeHttpClient)->queue(new HttpResponse(400, '{}'));

    $response = retrying($fake, maxAttempts: 3)->send(retryRequest());

    expect($response->status)->toBe(400)
        ->and($fake->requestCount())->toBe(1);
});

it('retries a retryable exception then succeeds', function (): void {
    $inner = new ThrowingHttpClient(
        new RetryableTransportException('connection reset'),
        failures: 1,
        response: new HttpResponse(200, '{"ok":true}'),
    );

    $client = retrying($inner, maxAttempts: 3, retryableExceptions: [RetryableTransportException::class]);

    $response = $client->send(retryRequest());

    expect($response->status)->toBe(200)
        ->and($inner->calls())->toBe(2);
});

it('re-throws a non-retryable exception immediately', function (): void {
    $inner = new ThrowingHttpClient(
        new LogicException('not retryable'),
        failures: 1,
        response: new HttpResponse(200, '{}'),
    );

    $client = retrying($inner, maxAttempts: 3, retryableExceptions: [RetryableTransportException::class]);

    expect(fn (): HttpResponse => $client->send(retryRequest()))
        ->toThrow(LogicException::class, 'not retryable');

    expect($inner->calls())->toBe(1);
});

it('sleeps for an exponential backoff schedule between retries', function (): void {
    $delays = [];
    $recorder = static function (int $microseconds) use (&$delays): void {
        $delays[] = $microseconds;
    };

    $fake = (new FakeHttpClient)
        ->queue(new HttpResponse(503, '{}'))
        ->queue(new HttpResponse(503, '{}'))
        ->queue(new HttpResponse(200, '{}'));

    retrying($fake, maxAttempts: 3, baseDelayMs: 200, sleeper: $recorder)->send(retryRequest());

    expect($delays)->toBe([200000, 400000]);
});
