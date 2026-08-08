<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paylink;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Support\Value;
use JsonException;

/**
 * Sends signed requests to the PayLink Payment Integration API via the HttpClient port.
 *
 * Each request carries the public token and a per-request HMAC signature in the JSON
 * body (built by {@see PaylinkSignedBody}); the merchant id holds the public token and
 * the shared secret is the signing hash token. An optional idempotency key is sent as
 * the `Idempotency-Key` header. Non-2xx responses become a {@see GatewayRequestException}.
 */
final readonly class PaylinkClient
{
    public function __construct(
        private HttpClient $http,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * Sign and POST the given params to an endpoint, returning the decoded response.
     *
     * @param  array<string, mixed>  $params  Field values keyed by wire (snake_case) name.
     * @param  string|null  $idempotencyKey  Optional value sent as the `Idempotency-Key` header for safe retries.
     * @return array<string, mixed>
     */
    public function post(PaylinkEndpoint $endpoint, array $params, ?string $idempotencyKey, string $context): array
    {
        $body = PaylinkSignedBody::build(
            $endpoint,
            $params,
            $this->credentials->merchantId,
            $this->credentials->sharedSecret,
        );

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        if (filled($idempotencyKey)) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $url = 'https://'.$this->credentials->host.$endpoint->value;
        $response = $this->http->send(new HttpRequest('POST', $url, $headers, $this->encode($body)));

        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, $context);
        }

        $json = $response->json();

        // PayLink wraps successful payloads in a { "data": {...}, "success": true }
        // envelope; unwrap it so callers read the response fields directly.
        return is_array($json['data'] ?? null) ? Value::array($json['data']) : $json;
    }

    /**
     * @param  array<string, string>  $body
     */
    private function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new GatewayRequestException(
                status: 0,
                responseBody: '',
                message: 'Failed to encode PayLink request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
