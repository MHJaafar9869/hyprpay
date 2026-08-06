<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Http;

use Hyprpay\Payments\Domain\Contract\HttpClient;

/**
 * Immutable value object describing an outbound HTTP request to a gateway API.
 *
 * Passed to the {@see HttpClient} port so the SDK can
 * build and sign requests independently of any concrete HTTP transport.
 */
final readonly class HttpRequest
{
    /**
     * Create an outbound HTTP request definition.
     *
     * @param  string  $method  HTTP verb (e.g. GET, POST) to use for the request.
     * @param  string  $url  Fully-qualified request URL.
     * @param  array<string, string>  $headers  Request headers keyed by header name.
     * @param  string|null  $body  Raw request body, or null when the request has none.
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
    ) {}

    /**
     * Return the value of a request header by name, or null when it is not set.
     *
     * @param  string  $name  Header name to look up.
     */
    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Determine whether the request carries a body.
     */
    public function hasBody(): bool
    {
        return $this->body !== null;
    }
}
