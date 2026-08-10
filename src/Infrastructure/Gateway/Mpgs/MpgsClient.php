<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Support\Value;
use JsonException;

/**
 * Low-level HTTP client for the Mastercard Payment Gateway Services (MPGS) REST API.
 *
 * Authenticates every request with HTTP Basic auth (username `merchant.{merchantId}`,
 * password the API password), resolves the absolute URL from the credential host and the
 * per-merchant, versioned base path, sends it through the SDK's HttpClient port, and
 * returns the decoded JSON. Non-2xx responses (request/validation errors) are converted
 * into a GatewayRequestException carrying the caller-supplied context label; business
 * declines are returned by MPGS as 200s with a FAILURE result and handled by the driver.
 */
final readonly class MpgsClient
{
    /**
     * MPGS REST API version used when the credentials do not override it.
     */
    private const DEFAULT_API_VERSION = '100';

    public function __construct(
        private HttpClient $http,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * Send a POST request and return the decoded JSON response body.
     *
     * @param  array<string, mixed>  $body  Request payload to JSON-encode.
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, string $context = ''): array
    {
        return $this->send('POST', $path, $this->encode($body), $context)->json();
    }

    /**
     * Send a PUT request and return the decoded JSON response body.
     *
     * @param  array<string, mixed>  $body  Request payload to JSON-encode.
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @return array<string, mixed>
     */
    public function put(string $path, array $body, string $context = ''): array
    {
        return $this->send('PUT', $path, $this->encode($body), $context)->json();
    }

    /**
     * Send a GET request and return the decoded JSON response body.
     *
     * @param  string  $context  Human-readable label used in error messages on failure.
     * @return array<string, mixed>
     */
    public function get(string $path, string $context = ''): array
    {
        return $this->send('GET', $path, null, $context)->json();
    }

    /**
     * Send a GET request and return the decoded JSON response body, or null when the
     * resource is not found (used for lookups that should return null rather than throw).
     *
     * @return array<string, mixed>|null
     */
    public function tryGet(string $path): ?array
    {
        $response = $this->dispatch('GET', $path, null);

        return $response->failed() ? null : $response->json();
    }

    /**
     * Dispatch a request and assert a successful status, wrapping failures in a
     * GatewayRequestException carrying the context label.
     *
     * @param  string|null  $payload  Raw request body, or null for bodyless (GET) requests.
     * @param  string  $context  Human-readable label used in error messages on failure.
     */
    private function send(string $method, string $path, ?string $payload, string $context): HttpResponse
    {
        $response = $this->dispatch($method, $path, $payload);

        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, $context);
        }

        return $response;
    }

    /**
     * Build the Basic-auth headers, resolve the absolute URL, and send the request
     * through the SDK HttpClient port.
     *
     * @param  string|null  $payload  Raw request body, or null for bodyless (GET) requests.
     */
    private function dispatch(string $method, string $path, ?string $payload): HttpResponse
    {
        $headers = [
            'Authorization' => $this->authorizationHeader(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        return $this->http->send(new HttpRequest($method, $this->url($path), $headers, $payload));
    }

    /**
     * Resolve the absolute request URL from the credential host and the per-merchant,
     * versioned base path.
     */
    private function url(string $path): string
    {
        return sprintf(
            'https://%s/api/rest/version/%s/merchant/%s%s',
            $this->credentials->host,
            $this->apiVersion(),
            rawurlencode($this->credentials->merchantId),
            $path,
        );
    }

    /**
     * Resolve the MPGS API version, honouring an `api_version` override in the credential
     * extras and falling back to the default.
     */
    private function apiVersion(): string
    {
        $version = Value::string($this->credentials->extra('api_version'), self::DEFAULT_API_VERSION);

        return $version !== '' ? $version : self::DEFAULT_API_VERSION;
    }

    /**
     * Build the HTTP Basic authorization header from the merchant id and API password.
     */
    private function authorizationHeader(): string
    {
        $token = base64_encode('merchant.'.$this->credentials->merchantId.':'.$this->credentials->sharedSecret);

        return 'Basic '.$token;
    }

    /**
     * JSON-encode a request body (slashes unescaped), wrapping any encoding failure in a
     * GatewayRequestException.
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
                message: 'Failed to encode MPGS request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
