<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\AuthorizeNet;

use Hyprpay\Payments\Domain\Contract\HttpClient;
use Hyprpay\Payments\Domain\Exception\GatewayRequestException;
use Hyprpay\Payments\Domain\Http\HttpRequest;
use Hyprpay\Payments\Domain\ValueObject\GatewayCredentials;
use Hyprpay\Payments\Infrastructure\Support\Value;
use JsonException;

/**
 * Sends requests to the Authorize.Net JSON API (the single `/xml/v1/request.api` endpoint).
 *
 * Authorize.Net authenticates in the request body via `merchantAuthentication` (API Login ID +
 * Transaction Key) rather than a header, so this client injects it into every envelope. Two
 * envelope shapes are used — {@see createTransaction()} for charge/capture/refund/void and
 * {@see getTransactionDetails()} for lookups — and both keep their child elements in the order
 * Authorize.Net's XML-backed schema requires. Non-2xx responses become a {@see GatewayRequestException};
 * the API otherwise returns HTTP 200 even for declines and errors, which the gateway interprets.
 */
final readonly class AuthorizeNetClient
{
    private const PATH = '/xml/v1/request.api';

    public function __construct(
        private HttpClient $http,
        private GatewayCredentials $credentials,
    ) {}

    /**
     * POST a createTransactionRequest and return the decoded response.
     *
     * @param  array<string, mixed>  $transactionRequest  The ordered transactionRequest body.
     * @param  string|null  $refId  Optional merchant reference (capped at Authorize.Net's 20 chars).
     * @return array<string, mixed>
     */
    public function createTransaction(array $transactionRequest, ?string $refId, string $context): array
    {
        $request = ['merchantAuthentication' => $this->merchantAuthentication()];

        if ($refId !== null && $refId !== '') {
            $request['refId'] = mb_substr($refId, 0, 20);
        }

        $request['transactionRequest'] = $transactionRequest;

        return $this->send(['createTransactionRequest' => $request], $context);
    }

    /**
     * POST a getTransactionDetailsRequest for a single transaction and return the decoded response.
     *
     * @return array<string, mixed>
     */
    public function getTransactionDetails(string $transactionId, string $context): array
    {
        return $this->send([
            'getTransactionDetailsRequest' => [
                'merchantAuthentication' => $this->merchantAuthentication(),
                'transId' => $transactionId,
            ],
        ], $context);
    }

    /**
     * POST a createCustomerProfileRequest to vault a payment profile and return the decoded response.
     *
     * The validation mode follows the credentials' test flag, so a live vault runs the card through
     * Authorize.Net's real validation and a sandbox vault through the test one.
     *
     * @param  array<string, mixed>  $profile  The ordered profile body (merchantCustomerId, paymentProfiles).
     * @return array<string, mixed>
     */
    public function createCustomerProfile(array $profile, string $context): array
    {
        return $this->send([
            'createCustomerProfileRequest' => [
                'merchantAuthentication' => $this->merchantAuthentication(),
                'profile' => $profile,
                'validationMode' => $this->credentials->testMode ? 'testMode' : 'liveMode',
            ],
        ], $context);
    }

    /**
     * Encode the envelope, POST it, and return the normalized decoded body.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(array $body, string $context): array
    {
        $response = $this->http->send(new HttpRequest(
            'POST',
            'https://'.$this->credentials->host.self::PATH,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $this->encode($body),
        ));

        if ($response->failed()) {
            throw GatewayRequestException::fromResponse($response, $context);
        }

        return $this->decode($response->body);
    }

    /**
     * The body-level credentials Authorize.Net authenticates each request with.
     *
     * @return array<string, string>
     */
    private function merchantAuthentication(): array
    {
        return [
            'name' => $this->credentials->merchantId,
            'transactionKey' => $this->credentials->sharedSecret,
        ];
    }

    /**
     * Decode a response body, stripping the UTF-8 BOM Authorize.Net prepends and unwrapping the
     * single `*Response` root element when present (the JSON API is inconsistent about it), so the
     * gateway always reads `messages`/`transactionResponse`/`transaction` from the top level.
     *
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        $decoded = Value::array(json_decode($body, true));

        if (count($decoded) === 1) {
            $key = array_key_first($decoded);
            $inner = $decoded[$key];

            if (is_array($inner) && str_ends_with($key, 'Response')) {
                return Value::array($inner);
            }
        }

        return $decoded;
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
                message: 'Failed to encode Authorize.Net request payload: '.$jsonException->getMessage(),
            );
        }
    }
}
