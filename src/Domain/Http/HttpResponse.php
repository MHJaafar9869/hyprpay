<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Http;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Immutable value object holding the result of an outbound gateway HTTP request.
 *
 * Returned by the {@see HttpClient} port and consumed by
 * drivers, exposing the status, raw body, headers, and JSON-decoding helpers.
 */
final readonly class HttpResponse
{
    /**
     * Create an HTTP response value object.
     *
     * @param  int  $status  HTTP status code returned by the gateway.
     * @param  string  $body  Raw response body.
     * @param  array<string, string|array<int, string>>  $headers  Response headers keyed by name; values may be a single string or a list of strings.
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    /**
     * Determine whether the response was successful (a 2xx status code).
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Determine whether the response indicates a failure (any non-2xx status).
     */
    public function failed(): bool
    {
        return ! $this->ok();
    }

    /**
     * Decode the response body as a JSON object.
     *
     * Returns an empty array when the body is not valid JSON or does not decode
     * to an array.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return Value::array(json_decode($this->body, true));
    }
}
