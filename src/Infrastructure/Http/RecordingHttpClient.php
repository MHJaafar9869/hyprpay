<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Http;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Http\ApiResponse;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Infrastructure\Support\Redactor;

/**
 * HttpClient decorator that captures each gateway API response for the monitoring dashboard.
 *
 * Sits alongside {@see LoggingHttpClient} in the transport stack and is switched on
 * separately, because it keeps far more than that one does: the method and full URL, the
 * request and response headers, and both bodies. Everything is masked by {@see Redactor}
 * first, so what lands in the store is a debuggable payload rather than live credentials
 * or cardholder data. A failed call is still recorded — a non-2xx response is usually the
 * one an operator opened the dashboard to read.
 */
final readonly class RecordingHttpClient implements HttpClient
{
    /**
     * @param  HttpClient  $inner  The wrapped client that performs the actual request.
     * @param  ApiResponseRecorder  $recorder  The request-scoped buffer responses are appended to.
     */
    public function __construct(
        private HttpClient $inner,
        private ApiResponseRecorder $recorder,
    ) {}

    /**
     * Delegate the request, then record the redacted response and its duration.
     */
    public function send(HttpRequest $request): HttpResponse
    {
        $startedAt = microtime(true);

        $response = $this->inner->send($request);

        $this->recorder->record(new ApiResponse(
            method: $request->method,
            url: $request->url,
            requestHeaders: Redactor::headers($request->headers),
            requestBody: Redactor::body($request->body),
            status: $response->status,
            responseHeaders: Redactor::headers($response->headers),
            responseBody: Redactor::body($response->body),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            recordedAt: date('c'),
        ));

        return $response;
    }
}
