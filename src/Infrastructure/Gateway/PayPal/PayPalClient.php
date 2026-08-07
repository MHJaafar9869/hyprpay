<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums\PayPalEndpoint;
use Hyprpay\Payments\Infrastructure\Support\Value;
use JsonException;

/**
 * Sends requests to the PayPal REST API (Orders v2 / Payments v2) via the HttpClient port.
 *
 * PayPal uses OAuth 2.0 client credentials: {@see authorize()} exchanges the client id
 * and secret (Basic auth) for a short-lived bearer token, which every subsequent order,
 * payment, vault, and webhook call carries in the `Authorization` header. The token is
 * cached for the lifetime of the client instance — one is built per gateway per request,
 * so a checkout that makes several calls authenticates only once — and non-2xx responses
 * are raised as a {@see GatewayRequestException} with the operation context.
 */
final class PayPalClient
{
    private ?string $token = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly GatewayCredentials $credentials,
    ) {}

    /**
     * POST to an endpoint and return the decoded JSON response.
     *
     * Passing null for the body sends no request body, as PayPal's capture/authorize/void
     * actions expect; an array is JSON-encoded. Extra headers (e.g. `PayPal-Request-Id`
     * for idempotency) are merged over the defaults.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function post(PayPalEndpoint $endpoint, ?array $body, string $context, string $id = '', array $headers = []): array
    {
        return $this->send('POST', $endpoint->path($id), $body === null ? null : $this->encode($body), $context, $headers);
    }

    /**
     * GET a resource by id and return the decoded JSON response.
     *
     * @return array<string, mixed>
     */
    public function get(PayPalEndpoint $endpoint, string $id, string $context): array
    {
        return $this->send('GET', $endpoint->path($id), null, $context);
    }

    /**
     * Send a bearer-authenticated request and decode the JSON body.
     *
     * @param  array<string, string>  $extraHeaders
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?string $body, string $context, array $extraHeaders = []): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->authorize(),
        ] + $extraHeaders;

        $response = $this->http->send(new HttpRequest($method, 'https://'.$this->credentials->host.$path, $headers, $body));

        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, $context);
        }

        return $response->json();
    }

    /**
     * Return a cached bearer token, exchanging the client credentials for one on first use.
     *
     * The client id is the merchant id and the client secret the shared secret; they are
     * sent as HTTP Basic auth to the OAuth token endpoint with `grant_type=client_credentials`.
     */
    private function authorize(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($this->credentials->merchantId.':'.$this->credentials->sharedSecret),
        ];

        $url = 'https://'.$this->credentials->host.PayPalEndpoint::OAuthToken->value;
        $response = $this->http->send(new HttpRequest('POST', $url, $headers, 'grant_type=client_credentials'));

        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, 'authenticate');
        }

        return $this->token = Value::string($response->json()['access_token'] ?? null);
    }

    /**
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
                message: 'Failed to encode PayPal request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
