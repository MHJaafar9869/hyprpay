<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Tamara;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\Http\HttpResponse;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use JsonException;

/**
 * Sends HTTP requests to the Tamara REST API via the SDK's HttpClient port.
 *
 * Tamara authenticates every request with a Bearer API/merchant token, so this client
 * attaches the Authorization header, resolves the environment-specific host from the
 * credentials, serialises the JSON body, and raises a {@see GatewayRequestException} on
 * transport-level (non-2xx) failures.
 */
final readonly class TamaraClient
{
    public function __construct(
        private HttpClient $http,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * POST a JSON body to a path and return the decoded JSON response.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $body, string $context = ''): array
    {
        $response = $this->send('POST', $path, $this->encode($body), $context);

        return $response->json();
    }

    /**
     * GET a path and return the decoded JSON response.
     *
     * @return array<string, mixed>
     */
    public function getJson(string $path, string $context = ''): array
    {
        $response = $this->send('GET', $path, null, $context);

        return $response->json();
    }

    private function send(string $method, string $path, ?string $payload, string $context): HttpResponse
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->credentials->sharedSecret,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $response = $this->http->send(new HttpRequest($method, 'https://'.$this->credentials->host.$path, $headers, $payload));
        $this->ensureOk($response, $context);

        return $response;
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
                message: 'Failed to encode Tamara request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
