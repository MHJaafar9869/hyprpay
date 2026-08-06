<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paylink;

/**
 * Builds the signed JSON body for a PayLink Payment Integration request.
 *
 * Walks the endpoint's ordered fields, coercing each supplied value to its wire
 * string (skipping absent optional fields entirely), collects the signed values in
 * order, and appends the public `token` and the computed `signature`. The body
 * values are the same coerced strings that are signed, so what is sent always
 * matches what is signed — exactly how the server reconstructs the signature.
 */
final class PaylinkSignedBody
{
    /**
     * @param  array<string, mixed>  $params  Field values keyed by wire (snake_case) name.
     * @return array<string, string> The request body, including token and signature.
     */
    public static function build(PaylinkEndpoint $endpoint, array $params, string $publicToken, string $hashToken): array
    {
        $body = [];
        $signedValues = [];

        foreach ($endpoint->fields() as $field) {
            $value = $params[$field['name']] ?? null;

            if ($value === null) {
                continue;
            }

            $string = PaylinkSignature::coerce($value);
            $body[$field['name']] = $string;

            if ($field['signed']) {
                $signedValues[] = $string;
            }
        }

        $body['token'] = $publicToken;
        $body['signature'] = PaylinkSignature::build($signedValues, $hashToken);

        return $body;
    }
}
