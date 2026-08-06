<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Http;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;

/**
 * In-memory test double implementing the {@see HttpClient} port.
 *
 * Records every request it is asked to send and returns queued responses in order
 * (falling back to a default), letting tests assert on outbound calls and stub
 * gateway replies without performing real HTTP.
 */
final class FakeHttpClient implements HttpClient
{
    /**
     * @var array<int, HttpRequest>
     */
    private array $recorded = [];

    /**
     * @var array<int, HttpResponse>
     */
    private array $queue = [];

    private readonly HttpResponse $default;

    /**
     * Create the fake client with an optional fallback response.
     *
     * @param  HttpResponse|null  $default  Response returned once the queue is exhausted; defaults to a 200 with an empty JSON object.
     */
    public function __construct(?HttpResponse $default = null)
    {
        $this->default = $default ?? new HttpResponse(200, '{}');
    }

    /**
     * Enqueue a response to be returned by the next unmatched call to send().
     *
     * @param  HttpResponse  $response  The stubbed response to append to the queue.
     * @return self For fluent chaining.
     */
    public function queue(HttpResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * Enqueue a JSON response, encoding the given array as the body.
     *
     * @param  array<string, mixed>  $json  Payload to JSON-encode as the response body.
     * @param  int  $status  HTTP status code for the stubbed response.
     * @return self For fluent chaining.
     */
    public function queueJson(array $json, int $status = 200): self
    {
        return $this->queue(new HttpResponse($status, (string) json_encode($json)));
    }

    /**
     * Enqueue a response with a raw string body.
     *
     * @param  string  $body  Raw body for the stubbed response.
     * @param  int  $status  HTTP status code for the stubbed response.
     * @return self For fluent chaining.
     */
    public function queueBody(string $body, int $status = 200): self
    {
        return $this->queue(new HttpResponse($status, $body));
    }

    /**
     * Record the request and return the next queued response, or the default.
     *
     * @param  HttpRequest  $request  The request being sent, which is recorded for later assertions.
     */
    public function send(HttpRequest $request): HttpResponse
    {
        $this->recorded[] = $request;

        return array_shift($this->queue) ?? $this->default;
    }

    /**
     * Return every request captured so far, in the order they were sent.
     *
     * @return array<int, HttpRequest>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * Return the most recently sent request, or null when none have been sent.
     */
    public function lastRequest(): ?HttpRequest
    {
        $key = array_key_last($this->recorded);

        return $key === null ? null : $this->recorded[$key];
    }

    /**
     * Return the number of requests sent through this fake client.
     */
    public function requestCount(): int
    {
        return count($this->recorded);
    }
}
