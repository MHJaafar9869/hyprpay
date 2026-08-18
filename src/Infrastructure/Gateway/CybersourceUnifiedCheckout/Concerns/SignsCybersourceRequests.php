<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Concerns;

/**
 * Builds CyberSource HMAC-SHA256 HTTP Signature authentication headers.
 *
 * Signing string header order per CyberSource: (request-target), host,
 * [digest], v-c-date, v-c-merchant-id. The digest is only present for
 * requests that carry a body. The shared secret is base64-encoded and is
 * decoded before being used as the HMAC key.
 *
 * The Accept header is always `application/hal+json`: CyberSource's edge returns
 * 404 "Resource not found" for `/pts/v2/payments` and `/risk/v1/*` when Accept is
 * `application/json` (only the Unified Checkout session endpoints tolerate the plain
 * type), which masquerades as an unprovisioned-account error. `hal+json` is accepted
 * by every endpoint, so it is used uniformly.
 */
trait SignsCybersourceRequests
{
    /**
     * @param  array{host: string, merchant_id: string, api_key_id: string, shared_secret: string}  $credentials
     * @return array<string, string>
     */
    protected function buildSignatureHeaders(
        string $method,
        string $resourcePath,
        array $credentials,
        ?string $payload,
        string $date,
    ): array {
        $host = $credentials['host'];
        $merchantId = $credentials['merchant_id'];
        $apiKeyId = $credentials['api_key_id'];
        $sharedSecret = $credentials['shared_secret'];

        $requestTarget = strtolower($method).' '.$resourcePath;

        $headers = [
            'v-c-merchant-id' => $merchantId,
            'v-c-date' => $date,
            'Host' => $host,
            'Content-Type' => 'application/json',
            'Accept' => 'application/hal+json;charset=utf-8',
        ];

        $signedHeaderNames = ['(request-target)', 'host'];
        $signatureParts = [
            "(request-target): {$requestTarget}",
            "host: {$host}",
        ];

        $carriesBody = $payload !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true);

        if ($carriesBody) {
            $digest = 'SHA-256='.base64_encode(hash('sha256', (string) $payload, true));
            $headers['Digest'] = $digest;
            $signedHeaderNames[] = 'digest';
            $signatureParts[] = "digest: {$digest}";
        }

        $signedHeaderNames[] = 'v-c-date';
        $signatureParts[] = "v-c-date: {$date}";

        $signedHeaderNames[] = 'v-c-merchant-id';
        $signatureParts[] = "v-c-merchant-id: {$merchantId}";

        $signatureString = implode("\n", $signatureParts);
        $decodedSecret = (string) base64_decode($sharedSecret, true);
        $signature = base64_encode(hash_hmac('sha256', $signatureString, $decodedSecret, true));

        $headers['Signature'] = sprintf(
            'keyid="%s", algorithm="HmacSHA256", headers="%s", signature="%s"',
            $apiKeyId,
            implode(' ', $signedHeaderNames),
            $signature,
        );

        return $headers;
    }

    /**
     * Returns the current UTC time formatted as the RFC 7231 HTTP date CyberSource
     * expects in the `v-c-date` header (e.g. "Mon, 02 Jan 2006 15:04:05 GMT").
     */
    protected function signatureDate(): string
    {
        return gmdate('D, d M Y H:i:s').' GMT';
    }
}
