<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns\SignsCybersourceRequests;
use JsonException;

/**
 * Low-level HTTP client for the CyberSource REST API.
 *
 * Signs each request with the CyberSource HMAC HTTP-Signature scheme (via the
 * SignsCybersourceRequests trait) using the supplied gateway credentials, sends
 * it through the SDK's HttpClient port against the credential's host, and returns
 * the decoded JSON, raw body, or HttpResponse. A path may carry a query string —
 * it is signed as part of the request target, exactly as it is sent. Non-2xx responses
 * are converted into a GatewayRequestException carrying the caller-supplied context label.
 */
final readonly class CybersourceClient
{
    use SignsCybersourceRequests;

    public function __construct(
        private HttpClient $http,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * Sends a signed POST request and returns the decoded JSON response body.
     *
     * @param  array<string, mixed>  $body  Request payload to JSON-encode and sign.
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @param  string|null  $idempotencyKey  Optional value sent as the `v-c-idempotency-id` header so CyberSource safely deduplicates retries.
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, string $context = '', ?string $idempotencyKey = null): array
    {
        return $this->postResponse($path, $body, $context, $idempotencyKey)->json();
    }

    /**
     * Sends a signed POST request and returns the raw (un-decoded) response body.
     *
     * Used for endpoints such as capture-contexts that return a bare JWT string
     * rather than a JSON object.
     *
     * @param  array<string, mixed>  $body  Request payload to JSON-encode and sign.
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @param  string|null  $idempotencyKey  Optional value sent as the `v-c-idempotency-id` header so CyberSource safely deduplicates retries.
     */
    public function postForBody(string $path, array $body, string $context = '', ?string $idempotencyKey = null): string
    {
        return $this->postResponse($path, $body, $context, $idempotencyKey)->body;
    }

    /**
     * Sends a signed POST request that carries no request body.
     *
     * Used by the action endpoints that act on a resource named entirely in the path —
     * cancelling, suspending, or reactivating a subscription. CyberSource still signs a
     * digest for such a POST, so an empty payload is passed through rather than omitted:
     * the digest covers the empty string, exactly as CyberSource's own SDKs sign it.
     *
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @param  string|null  $idempotencyKey  Optional value sent as the `v-c-idempotency-id` header so CyberSource safely deduplicates retries.
     * @return array<string, mixed>
     */
    public function postWithoutBody(string $path, string $context = '', ?string $idempotencyKey = null): array
    {
        $response = $this->dispatch('POST', $path, '', $idempotencyKey);
        $this->ensureOk($response, $context);

        return $response->json();
    }

    /**
     * Sends a signed PATCH request and returns the decoded JSON response body.
     *
     * Used for the partial-update endpoints (amending a subscription), where only the fields
     * being changed are sent. PATCH is signed exactly like POST — the digest covers the body.
     *
     * @param  array<string, mixed>  $body  Request payload to JSON-encode and sign.
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @param  string|null  $idempotencyKey  Optional value sent as the `v-c-idempotency-id` header so CyberSource safely deduplicates retries.
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body, string $context = '', ?string $idempotencyKey = null): array
    {
        $response = $this->dispatch('PATCH', $path, $this->encode($body), $idempotencyKey);
        $this->ensureOk($response, $context);

        return $response->json();
    }

    /**
     * Sends a signed PUT request and returns the decoded JSON response body.
     *
     * Used by the create-or-replace endpoints (a report subscription, keyed by report name),
     * where the same call both creates and updates. Signed like POST — the digest covers the body.
     *
     * @param  array<string, mixed>  $body  Request payload to JSON-encode and sign.
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @param  string|null  $idempotencyKey  Optional value sent as the `v-c-idempotency-id` header so CyberSource safely deduplicates retries.
     * @return array<string, mixed>
     */
    public function put(string $path, array $body, string $context = '', ?string $idempotencyKey = null): array
    {
        $response = $this->dispatch('PUT', $path, $this->encode($body), $idempotencyKey);
        $this->ensureOk($response, $context);

        return $response->json();
    }

    /**
     * Sends a signed DELETE request and returns the decoded JSON response body.
     *
     * DELETE carries no body, and CyberSource does not sign a digest for it, so no payload is
     * sent. The response is typically empty; callers read only the HTTP status.
     *
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @return array<string, mixed>
     */
    public function delete(string $path, string $context = ''): array
    {
        $response = $this->dispatch('DELETE', $path, null);
        $this->ensureOk($response, $context);

        return $response->json();
    }

    /**
     * Sends a signed GET request and returns the decoded JSON response body.
     *
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @return array<string, mixed>
     */
    public function get(string $path, string $context = ''): array
    {
        $response = $this->dispatch('GET', $path, null);
        $this->ensureOk($response, $context);

        return $response->json();
    }

    /**
     * Sends a signed GET request and returns the raw (un-decoded) response body.
     *
     * Used for the endpoints that return a file rather than JSON — a report download, whose body
     * is the CSV or XML report itself. The Accept header must match the format the report was
     * generated in, so it is passed explicitly rather than left at the JSON default.
     *
     * @param  string  $accept  Accept header for the response format (e.g. `text/csv`).
     * @param  string  $context  Human-readable label used in error messages on failure.
     */
    public function getBody(string $path, string $accept, string $context = ''): string
    {
        $response = $this->dispatch('GET', $path, null, null, $accept);
        $this->ensureOk($response, $context);

        return $response->body;
    }

    /**
     * Encodes, signs, and dispatches a POST request, asserting a successful status.
     *
     * @param  array<string, mixed>  $body  Request payload to JSON-encode and sign.
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @param  string|null  $idempotencyKey  Optional idempotency key sent as an (unsigned) header.
     */
    private function postResponse(string $path, array $body, string $context, ?string $idempotencyKey = null): HttpResponse
    {
        $response = $this->dispatch('POST', $path, $this->encode($body), $idempotencyKey);
        $this->ensureOk($response, $context);

        return $response;
    }

    /**
     * Builds the signature headers, resolves the absolute URL from the credential
     * host, and sends the request through the SDK HttpClient port.
     *
     * When an idempotency key is supplied it is added as the `v-c-idempotency-id`
     * header. CyberSource does not require this header to be part of the signed
     * header set, so it is attached after signing.
     *
     * @param  string|null  $payload  Raw request body, or null for bodyless (GET/DELETE) requests.
     * @param  string|null  $idempotencyKey  Optional idempotency key for safe retries.
     * @param  string|null  $accept  Accept header override for a non-JSON response (a report file).
     */
    private function dispatch(string $method, string $path, ?string $payload, ?string $idempotencyKey = null, ?string $accept = null): HttpResponse
    {
        $headers = $this->buildSignatureHeaders(
            $method,
            $path,
            $this->credentials->toSigningArray(),
            $payload,
            $this->signatureDate(),
            ...($accept === null ? [] : [$accept]),
        );

        if (filled($idempotencyKey)) {
            $headers['v-c-idempotency-id'] = $idempotencyKey;
        }

        $url = sprintf('https://%s%s', $this->credentials->host, $path);

        return $this->http->send(new HttpRequest($method, $url, $headers, $payload));
    }

    /**
     * Throws a GatewayRequestException carrying the context label when the
     * response reports a non-successful HTTP status.
     */
    private function ensureOk(HttpResponse $response, string $context): void
    {
        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, $context);
        }
    }

    /**
     * JSON-encodes a request body (slashes unescaped), wrapping any encoding
     * failure in a GatewayRequestException.
     *
     * @param  array<string, mixed>  $body
     */
    private function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new GatewayRequestException(
                status: 0,
                responseBody: '',
                message: 'Failed to encode CyberSource request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
