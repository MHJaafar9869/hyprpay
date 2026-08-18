<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Airwallex;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Airwallex\Enums\AirwallexEndpoint;
use Hyprpay\Payments\Infrastructure\Support\Value;
use JsonException;

/**
 * Sends requests to the Airwallex "Online Payments" REST API via the HttpClient port.
 *
 * Airwallex authenticates with an API-access login: {@see authorize()} exchanges the client id
 * and API key (sent as `x-client-id`/`x-api-key` headers) for a short-lived bearer token, which
 * every subsequent payment-intent and refund call carries in the `Authorization` header. The token
 * is cached for the lifetime of the client instance — one is built per gateway per request, so a
 * checkout that makes several calls authenticates only once. Each request also carries the
 * `x-client-id` header and, when configured, the `x-api-version` and `x-login-as` (connected-account)
 * headers. Non-2xx responses are raised as a {@see GatewayRequestException} with the operation context.
 */
final class AirwallexClient
{
    private ?string $token = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly GatewayCredentials $credentials,
    ) {}

    /**
     * POST to an endpoint and return the decoded JSON response.
     *
     * Passing null for the body sends no request body; an array is JSON-encoded.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    public function post(AirwallexEndpoint $endpoint, ?array $body, string $context, string $id = ''): array
    {
        return $this->send('POST', $endpoint->path($id), $body === null ? null : $this->encode($body), $context);
    }

    /**
     * GET a resource by id and return the decoded JSON response.
     *
     * @return array<string, mixed>
     */
    public function get(AirwallexEndpoint $endpoint, string $id, string $context): array
    {
        return $this->send('GET', $endpoint->path($id), null, $context);
    }

    /**
     * GET a collection endpoint with query parameters and return the decoded JSON response.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    public function query(AirwallexEndpoint $endpoint, array $query, string $context): array
    {
        $path = $endpoint->path();

        if ($query !== []) {
            $path .= '?'.http_build_query($query);
        }

        return $this->send('GET', $path, null, $context);
    }

    /**
     * Send a bearer-authenticated request and decode the JSON body.
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?string $body, string $context): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->authorize(),
        ] + $this->scopeHeaders();

        $response = $this->http->send(new HttpRequest($method, 'https://'.$this->credentials->host.$path, $headers, $body));

        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, $context);
        }

        return $response->json();
    }

    /**
     * Return a cached bearer token, exchanging the API-access credentials for one on first use.
     *
     * The client id and API key are sent as the `x-client-id`/`x-api-key` headers to the login
     * endpoint, together with the optional api-version and connected-account scope headers.
     */
    private function authorize(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-api-key' => $this->credentials->sharedSecret,
        ] + $this->scopeHeaders();

        $url = 'https://'.$this->credentials->host.AirwallexEndpoint::Login->value;
        $response = $this->http->send(new HttpRequest('POST', $url, $headers));

        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, 'authenticate');
        }

        return $this->token = Value::string($response->json()['token'] ?? null);
    }

    /**
     * Build the request-scoping headers: the client id on every call, plus the api-version and
     * connected-account (`x-login-as`) headers when they are configured.
     *
     * @return array<string, string>
     */
    private function scopeHeaders(): array
    {
        $headers = ['x-client-id' => $this->credentials->merchantId];

        $apiVersion = Value::nullableString($this->credentials->extra('api_version'));

        if ($apiVersion !== null) {
            $headers['x-api-version'] = $apiVersion;
        }

        $accountId = Value::nullableString($this->credentials->extra('account_id'));

        if ($accountId !== null) {
            $headers['x-login-as'] = $accountId;
        }

        return $headers;
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
                message: 'Failed to encode Airwallex request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
