<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Http;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Psr\Log\LoggerInterface;

/**
 * HttpClient decorator that logs request/response metadata via a PSR-3 logger.
 *
 * Wraps any {@see HttpClient} and records the method, target URL, and response status
 * for each call — a successful response at debug level, a failed one at warning level.
 * It deliberately logs metadata ONLY: request/response headers and bodies are never
 * logged (they carry signatures, secrets, and possibly cardholder data), and the URL's
 * query string is stripped (it can contain a signature). This keeps observability
 * without leaking sensitive data.
 */
final readonly class LoggingHttpClient implements HttpClient
{
    /**
     * @param  HttpClient  $inner  The wrapped client that performs the actual request.
     * @param  LoggerInterface  $logger  PSR-3 logger the metadata is written to.
     */
    public function __construct(
        private HttpClient $inner,
        private LoggerInterface $logger,
    ) {}

    /**
     * Log the request, delegate to the inner client, then log the response outcome.
     */
    public function send(HttpRequest $request): HttpResponse
    {
        $target = $this->target($request);

        $this->logger->debug('gateway.http.request', ['method' => $request->method, 'url' => $target]);

        $response = $this->inner->send($request);

        $context = ['method' => $request->method, 'url' => $target, 'status' => $response->status];

        if ($response->failed()) {
            $this->logger->warning('gateway.http.response.failed', $context);

            return $response;
        }

        $this->logger->debug('gateway.http.response', $context);

        return $response;
    }

    /**
     * Return the request URL without its query string, which may carry a signature.
     */
    private function target(HttpRequest $request): string
    {
        $base = strtok($request->url, '?');

        return $base === false ? $request->url : $base;
    }
}
