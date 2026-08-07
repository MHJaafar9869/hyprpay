<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paytabs;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Gateway\Paytabs\Enums\PaytabsEndpoint;
use JsonException;

/**
 * Sends requests to the PayTabs PT2 REST endpoints via the SDK's HttpClient port.
 *
 * PayTabs authenticates each request with the merchant server key in the
 * `Authorization` header (the shared secret) and identifies the merchant with the
 * `profile_id` in the JSON body, so no request signing is done here. The client
 * resolves the region host from the credentials, serialises the body, and raises a
 * {@see GatewayRequestException} on transport-level (non-2xx) failures.
 */
final readonly class PaytabsClient
{
    public function __construct(
        private HttpClient $http,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * POST a JSON body to an endpoint and return the decoded JSON response.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(PaytabsEndpoint $endpoint, array $body, string $context = ''): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => $this->credentials->sharedSecret,
        ];

        $url = 'https://'.$this->credentials->host.$endpoint->value;
        $response = $this->http->send(new HttpRequest('POST', $url, $headers, $this->encode($body)));

        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, $context);
        }

        return $response->json();
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
                message: 'Failed to encode PayTabs request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
