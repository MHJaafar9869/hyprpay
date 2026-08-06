<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Fawry;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\Enums\FawryEndpoint;
use JsonException;

/**
 * Sends HTTP requests to the FawryPay REST endpoints via the SDK's HttpClient port.
 *
 * FawryPay authenticates each request with a signature field inside the JSON body
 * (built by {@see FawrySignature}), so no signed headers are added here. The client
 * only resolves the environment-specific URL, serialises the body, and raises a
 * {@see GatewayRequestException} on transport-level (non-2xx) failures.
 */
final readonly class FawryClient
{
    public function __construct(
        private HttpClient $http,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * POST a JSON body and return the decoded JSON response.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function postJson(FawryEndpoint $endpoint, array $body, string $context = ''): array
    {
        $response = $this->send('POST', $endpoint->url($this->credentials->testMode), $this->encode($body));
        $this->ensureOk($response, $context);

        return $response->json();
    }

    /**
     * POST a JSON body and return the raw response body (used by hosted-checkout init,
     * whose success response is the redirect URL as plain text).
     *
     * @param  array<string, mixed>  $body
     */
    public function postForBody(FawryEndpoint $endpoint, array $body, string $context = ''): string
    {
        $response = $this->send('POST', $endpoint->url($this->credentials->testMode), $this->encode($body));
        $this->ensureOk($response, $context);

        return $response->body;
    }

    /**
     * GET an endpoint with query parameters and return the decoded JSON response.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    public function getJson(FawryEndpoint $endpoint, array $query, string $context = ''): array
    {
        $url = $endpoint->url($this->credentials->testMode).'?'.http_build_query($query);
        $response = $this->send('GET', $url, null);
        $this->ensureOk($response, $context);

        return $response->json();
    }

    private function send(string $method, string $url, ?string $payload): HttpResponse
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        return $this->http->send(new HttpRequest($method, $url, $headers, $payload));
    }

    private function ensureOk(HttpResponse $response, string $context): void
    {
        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, $context);
        }
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
                message: 'Failed to encode FawryPay request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
