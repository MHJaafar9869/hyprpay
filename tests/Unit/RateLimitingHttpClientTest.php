<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;
use Hyprpay\Payments\Infrastructure\Http\RateLimitingHttpClient;

function rateLimitRequest(): HttpRequest
{
    return new HttpRequest('POST', 'https://gateway.test/charge');
}

it('lets an initial burst up to capacity through without waiting', function (): void {
    $now = 0.0;
    $sleeps = [];
    $fake = new FakeHttpClient;

    $client = new RateLimitingHttpClient(
        $fake,
        maxRequests: 3,
        perSeconds: 1,
        clock: static fn (): float => $now,
        sleeper: static function (int $microseconds) use (&$sleeps): void {
            $sleeps[] = $microseconds;
        },
    );

    $client->send(rateLimitRequest());
    $client->send(rateLimitRequest());
    $client->send(rateLimitRequest());

    expect($sleeps)->toBe([])
        ->and($fake->requestCount())->toBe(3);
});

it('blocks for one refill interval once the bucket is empty', function (): void {
    $now = 0.0;
    $sleeps = [];
    $fake = new FakeHttpClient;

    // 2 requests / second → the bucket refills one token every 0.5s (500000µs).
    $client = new RateLimitingHttpClient(
        $fake,
        maxRequests: 2,
        perSeconds: 1,
        clock: static fn (): float => $now,
        sleeper: static function (int $microseconds) use (&$sleeps): void {
            $sleeps[] = $microseconds;
        },
    );

    $client->send(rateLimitRequest()); // token 2 → 1
    $client->send(rateLimitRequest()); // token 1 → 0
    $client->send(rateLimitRequest()); // empty: waits for one token

    expect($sleeps)->toBe([500000])
        ->and($fake->requestCount())->toBe(3);
});

it('does not wait when the bucket has refilled by the next call', function (): void {
    $now = 0.0;
    $sleeps = [];
    $fake = new FakeHttpClient;

    $client = new RateLimitingHttpClient(
        $fake,
        maxRequests: 1,
        perSeconds: 1,
        clock: static function () use (&$now): float {
            return $now;
        },
        sleeper: static function (int $microseconds) use (&$sleeps): void {
            $sleeps[] = $microseconds;
        },
    );

    $client->send(rateLimitRequest());

    // A full second passes, refilling the single token the first call spent.
    $now = 1.0;

    $client->send(rateLimitRequest());

    expect($sleeps)->toBe([])
        ->and($fake->requestCount())->toBe(2);
});

it('keeps throttling at a steady interval while the clock is frozen', function (): void {
    $now = 0.0;
    $sleeps = [];
    $fake = new FakeHttpClient;

    $client = new RateLimitingHttpClient(
        $fake,
        maxRequests: 2,
        perSeconds: 1,
        clock: static fn (): float => $now,
        sleeper: static function (int $microseconds) use (&$sleeps): void {
            $sleeps[] = $microseconds;
        },
    );

    foreach (range(1, 4) as $ignored) {
        $client->send(rateLimitRequest());
    }

    // Two burst tokens pass free; the next two each wait exactly one 0.5s interval
    // — advancing lastTick by the wait keeps the spacing steady, never runaway.
    expect($sleeps)->toBe([500000, 500000]);
});

it('returns the response from the inner client', function (): void {
    $fake = (new FakeHttpClient)->queue(new HttpResponse(201, '{"ok":true}'));

    // Capacity leaves the first call unthrottled, so this never hits a real sleep.
    $client = new RateLimitingHttpClient($fake, maxRequests: 5, perSeconds: 1);

    $response = $client->send(rateLimitRequest());

    expect($response->status)->toBe(201)
        ->and($response->body)->toBe('{"ok":true}');
});
