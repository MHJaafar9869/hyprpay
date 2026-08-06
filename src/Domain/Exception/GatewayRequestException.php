<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Exception;

use Hyprpay\Payments\Domain\Http\HttpResponse;

/**
 * Thrown when an HTTP call to the gateway API returns an error (non-2xx) response.
 *
 * Carries the HTTP status, the raw response body, and the decoded response payload
 * so callers can inspect the failure or decide whether to retry it.
 */
final class GatewayRequestException extends GatewayException
{
    /**
     * Capture the details of a failed gateway API request.
     *
     * @param  int  $status  HTTP status code returned by the gateway.
     * @param  string  $responseBody  Raw, undecoded response body from the gateway.
     * @param  array<string, mixed>  $response  Decoded response payload, when the body was JSON.
     * @param  string  $message  Optional human-readable message; a default is derived from the status when empty.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $responseBody,
        public readonly array $response = [],
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Gateway request failed with HTTP {$status}.");
    }

    /**
     * Build the exception from a gateway HTTP response, extracting a human-readable reason.
     *
     * Decodes the JSON body, pulls a reason/message/status field for the error text,
     * and prefixes it with the optional operation context.
     *
     * @param  HttpResponse  $response  The failed gateway HTTP response to wrap.
     * @param  string  $context  Optional label (e.g. the operation name) prepended to the message.
     */
    public static function fromResponse(HttpResponse $response, string $context = ''): self
    {
        $decoded = $response->json();
        $reason = self::extractReason($decoded);
        $prefix = $context !== '' ? "{$context}: " : '';
        $suffix = $reason !== null ? " ({$reason})" : '';

        return new self(
            status: $response->status,
            responseBody: $response->body,
            response: $decoded,
            message: "{$prefix}CyberSource returned HTTP {$response->status}{$suffix}.",
        );
    }

    /**
     * Determine whether the failure is transient and the request may safely be retried.
     *
     * Returns true for status codes that indicate timeouts, rate limiting, or
     * temporary gateway unavailability (408, 429, 502, 503, 504).
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, [408, 429, 502, 503, 504], true);
    }

    /**
     * Extract a human-readable reason string from a decoded gateway error payload.
     *
     * Looks at the `reason`, `message`, then `status` keys and returns the first
     * string value found, or null when none is present.
     *
     * @param  array<string, mixed>  $decoded  Decoded gateway response payload.
     */
    private static function extractReason(array $decoded): ?string
    {
        $reason = $decoded['reason'] ?? $decoded['message'] ?? $decoded['status'] ?? null;

        return is_string($reason) ? $reason : null;
    }
}
