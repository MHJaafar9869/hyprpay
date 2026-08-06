<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;
use Hyprpay\Payments\Infrastructure\Http\LoggingHttpClient;
use Psr\Log\AbstractLogger;

/**
 * In-memory PSR-3 logger that records every log call for later assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * @var array<int, array{level: mixed, message: string|Stringable, context: array<mixed>}>
     */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}

/**
 * Build a LoggingHttpClient whose inner client returns the given response.
 */
function loggingClient(HttpResponse $response, RecordingLogger $logger): LoggingHttpClient
{
    $inner = (new FakeHttpClient)->queue($response);

    return new LoggingHttpClient($inner, $logger);
}

it('logs a request and a response record at debug level with method, url and status', function (): void {
    $logger = new RecordingLogger;
    $client = loggingClient(new HttpResponse(200, '{"ok":true}'), $logger);

    $client->send(new HttpRequest('POST', 'https://gateway.test/charge'));

    expect($logger->records)->toHaveCount(2);

    [$request, $response] = $logger->records;

    expect($request['level'])->toBe('debug')
        ->and($request['message'])->toBe('gateway.http.request')
        ->and($request['context'])->toBe(['method' => 'POST', 'url' => 'https://gateway.test/charge'])
        ->and($response['level'])->toBe('debug')
        ->and($response['message'])->toBe('gateway.http.response')
        ->and($response['context'])->toBe([
            'method' => 'POST',
            'url' => 'https://gateway.test/charge',
            'status' => 200,
        ]);
});

it('logs a failed response at warning level', function (): void {
    $logger = new RecordingLogger;
    $client = loggingClient(new HttpResponse(422, '{"error":"bad"}'), $logger);

    $client->send(new HttpRequest('POST', 'https://gateway.test/charge'));

    [, $response] = $logger->records;

    expect($response['level'])->toBe('warning')
        ->and($response['message'])->toBe('gateway.http.response.failed')
        ->and($response['context'])->toBe([
            'method' => 'POST',
            'url' => 'https://gateway.test/charge',
            'status' => 422,
        ]);
});

it('never logs the request body, response body, headers or secret values', function (): void {
    $logger = new RecordingLogger;
    $client = loggingClient(
        new HttpResponse(200, '{"card":"4111111111111111","secret":"resp-secret"}', ['X-Secret-Header' => 'resp-header-secret']),
        $logger,
    );

    $request = new HttpRequest(
        'POST',
        'https://gateway.test/charge',
        ['Authorization' => 'Bearer super-secret-token', 'X-Api-Key' => 'header-secret'],
        '{"pan":"4111111111111111","cvv":"123"}',
    );

    $client->send($request);

    $forbidden = [
        'super-secret-token',
        'header-secret',
        'resp-header-secret',
        'resp-secret',
        '4111111111111111',
        '123',
        'cvv',
        'pan',
        'Authorization',
        'X-Api-Key',
        'X-Secret-Header',
    ];

    foreach ($logger->records as $record) {
        $encoded = json_encode($record['context']);

        foreach ($forbidden as $needle) {
            expect($encoded)->not->toContain($needle);
        }
    }
});

it('strips the query string from the logged url so a signature never leaks', function (): void {
    $logger = new RecordingLogger;
    $client = loggingClient(new HttpResponse(200, '{}'), $logger);

    $client->send(new HttpRequest('GET', 'https://gateway.test/status?signature=SECRET&id=42'));

    foreach ($logger->records as $record) {
        $encoded = json_encode($record['context']);

        expect($encoded)->not->toContain('SECRET')
            ->and($encoded)->not->toContain('signature')
            ->and($record['context']['url'])->toBe('https://gateway.test/status');
    }
});
