<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Http;

/**
 * One recorded API response from a gateway, together with the request that produced it.
 *
 * Captured by the RecordingHttpClient decorator when API-response recording is switched on,
 * and attached to the activity record of the operation that produced it, so the dashboard
 * can show what was actually sent and received. Sensitive headers and body fields are
 * masked before an instance is constructed — see the Redactor — so a record holds a
 * debuggable payload, never live credentials or cardholder data.
 */
final readonly class ApiResponse
{
    /**
     * @param  string  $method  HTTP method the driver used.
     * @param  string  $url  Target URL, query string included.
     * @param  array<string, string>  $requestHeaders  Redacted request headers.
     * @param  string|null  $requestBody  Redacted request body, pretty-printed when it was JSON.
     * @param  int  $status  HTTP status the gateway returned.
     * @param  array<string, string>  $responseHeaders  Redacted response headers.
     * @param  string|null  $responseBody  Redacted response body, pretty-printed when it was JSON.
     * @param  int  $durationMs  Wall-clock duration of the call in milliseconds.
     * @param  string  $recordedAt  ISO-8601 timestamp of when the call completed.
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $requestHeaders,
        public ?string $requestBody,
        public int $status,
        public array $responseHeaders,
        public ?string $responseBody,
        public int $durationMs,
        public string $recordedAt,
    ) {}

    /**
     * Represent the response as a plain array for storage and for the dashboard payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'requestHeaders' => $this->requestHeaders,
            'requestBody' => $this->requestBody,
            'status' => $this->status,
            'responseHeaders' => $this->responseHeaders,
            'responseBody' => $this->responseBody,
            'durationMs' => $this->durationMs,
            'recordedAt' => $this->recordedAt,
        ];
    }
}
