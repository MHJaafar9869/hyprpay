<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Support\Value;
use JsonException;

/**
 * Sends requests to the Paymob Accept REST API via the SDK's HttpClient port.
 *
 * Paymob authenticates with a short-lived bearer token obtained from the API key;
 * {@see authenticate()} exchanges the key for a token that callers pass to the
 * subsequent order, payment-key, refund, void, and inquiry calls. Non-2xx responses
 * are converted into a {@see GatewayRequestException} carrying the operation context.
 */
final readonly class PaymobClient
{
    private const BASE_URL = 'https://accept.paymob.com/api';

    public function __construct(
        private HttpClient $http,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * Exchange the merchant API key for a short-lived Paymob auth token.
     */
    public function authenticate(): string
    {
        $response = $this->post('/auth/tokens', ['api_key' => $this->credentials->sharedSecret], null, 'authenticate');

        return Value::string($response['token'] ?? null);
    }

    /**
     * POST a JSON body (optionally bearer-authenticated) and return the decoded response.
     *
     * @param  array<string, mixed>  $body
     * @param  string|null  $token  Bearer auth token, or null for the unauthenticated auth call.
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, ?string $token, string $context): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        if (filled($token)) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $response = $this->http->send(new HttpRequest('POST', self::BASE_URL.$path, $headers, $this->encode($body)));

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
                message: 'Failed to encode Paymob request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
