<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Http;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Infrastructure\Support\Value;
use Illuminate\Http\Client\Factory;

/**
 * Adapter that fulfils the {@see HttpClient} port using Laravel's HTTP client.
 *
 * Translates the SDK's {@see HttpRequest} value object into a call on Illuminate's
 * HTTP client factory and maps the result back into an {@see HttpResponse}.
 */
final readonly class LaravelHttpClient implements HttpClient
{
    /**
     * Wire up the adapter with Laravel's HTTP client factory.
     *
     * @param  Factory  $http  Illuminate HTTP client factory used to dispatch requests.
     * @param  int  $timeout  Request timeout in seconds applied to every call.
     */
    public function __construct(
        private Factory $http,
        private int $timeout = 30,
    ) {}

    /**
     * Send the given request through Laravel's HTTP client and map the result.
     *
     * Applies the request headers and timeout, attaches the body with its content
     * type when present, then returns the outcome as an {@see HttpResponse}.
     *
     * @param  HttpRequest  $request  The outbound request to dispatch.
     */
    public function send(HttpRequest $request): HttpResponse
    {
        $pending = $this->http
            ->withHeaders($request->headers)
            ->timeout($this->timeout);

        $pending = match ($request->hasBody()) {
            true => $pending->withBody((string) $request->body, $this->contentType($request)),
            false => $pending,
        };

        $response = $pending->send($request->method, $request->url);

        return new HttpResponse(
            status: $response->status(),
            body: $response->body(),
            headers: $this->normalizeHeaders($response->headers()),
        );
    }

    /**
     * Narrow Laravel's loosely-typed response headers into the shape HttpResponse expects.
     *
     * Laravel's HTTP client declares `headers()` as a bare `array`; this coerces each
     * entry to a string-keyed name mapping either to a list of string values (the
     * PSR-7 shape) or a single string, discarding nothing.
     *
     * @param  array<mixed, mixed>  $headers  Raw response headers as returned by Laravel's HTTP client.
     * @return array<string, array<int, string>|string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            $normalized[Value::string($name)] = is_array($values)
                ? array_map(static fn ($value): string => Value::string($value), array_values($values))
                : Value::string($values);
        }

        return $normalized;
    }

    /**
     * Resolve the content type to send the body with, defaulting to JSON.
     *
     * Uses the request's own `Content-Type` header when set, otherwise falls back
     * to `application/json`.
     *
     * @param  HttpRequest  $request  The request whose content type is being resolved.
     */
    private function contentType(HttpRequest $request): string
    {
        return $request->header('Content-Type') ?? 'application/json';
    }
}
