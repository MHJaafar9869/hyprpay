<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Support;

/**
 * Masks credentials and cardholder data out of gateway HTTP payloads before they are stored.
 *
 * The activity store is PII-safe by design; recorded exchanges are the one place a raw
 * gateway payload is kept, so everything that goes in passes through here first. Matching
 * is on the header or key name, case- and separator-insensitive ("card_number", "cardNumber"
 * and "CARD-NUMBER" all match), and applies at every depth of a JSON body. A body that is
 * not JSON is replaced wholesale rather than guessed at, because an unparsed form-encoded
 * or XML payload cannot be masked field by field.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    public const PAN_MASK = '****';

    /**
     * Header names whose values are always credentials or signatures.
     *
     * @var list<string>
     */
    private const HEADERS = [
        'authorization', 'proxyauthorization', 'cookie', 'setcookie', 'apikey', 'xapikey',
        'signature', 'vcsignature', 'xsignature', 'digest', 'xauthtoken', 'token',
        'xvpsauth', 'merchantauthentication', 'clientsecret', 'xclientsecret',
    ];

    /**
     * Body keys that carry cardholder data, 3-D Secure authentication values, or credentials
     * at any depth. The cryptogram family (cavv, and the names other schemes give it) belongs
     * here as firmly as the CVV: PCI-DSS forbids retaining it once authorisation is done.
     *
     * @var list<string>
     */
    private const KEYS = [
        'cvv', 'cvc', 'cvn', 'cvv2', 'securitycode', 'cardsecuritycode',
        'cavv', 'cavv2', 'cryptogram', 'authenticationvalue', 'ucafauthenticationdata',
        'expirationmonth', 'expirationyear', 'expirymonth', 'expiryyear', 'expiry', 'expdate',
        'password', 'secret', 'sharedsecret', 'clientsecret', 'apikey', 'apitoken',
        'transactionkey', 'signature', 'authorization', 'accesstoken', 'refreshtoken',
        'accountnumber', 'iban', 'routingnumber', 'securekey', 'hashtoken', 'serverkey',
    ];

    /**
     * Card-number keys, truncated to their last four rather than removed outright.
     *
     * @var list<string>
     */
    private const PAN_KEYS = ['number', 'cardnumber', 'pan', 'primaryaccountnumber'];

    /**
     * Mask every sensitive header, preserving the original header names.
     *
     * @param  array<string, string|array<int, string>>  $headers  Raw headers, values possibly repeated.
     * @return array<string, string> Header names mapped to a single, possibly masked, value.
     */
    public static function headers(array $headers): array
    {
        $safe = [];

        foreach ($headers as $name => $value) {
            $flat = is_array($value) ? implode(', ', $value) : $value;
            $safe[$name] = self::matches((string) $name, self::HEADERS) ? self::MASK : $flat;
        }

        return $safe;
    }

    /**
     * Mask a request or response body, pretty-printing it when it is JSON.
     *
     * @param  string|null  $body  The raw body as it went over the wire.
     * @return string|null The masked body, or null when there was none.
     */
    public static function body(?string $body): ?string
    {
        if (blank($body)) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return self::MASK;
        }

        return Value::string(json_encode(self::values($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), self::MASK);
    }

    /**
     * Walk a decoded body and mask the value of every sensitive key at any depth.
     *
     * @param  array<array-key, mixed>  $body  The decoded payload.
     * @return array<array-key, mixed> The payload with sensitive values replaced.
     */
    private static function values(array $body): array
    {
        $safe = [];

        foreach ($body as $key => $value) {
            $safe[$key] = match (true) {
                is_string($key) && self::matches($key, self::PAN_KEYS) => self::pan($value),
                is_string($key) && self::matches($key, self::KEYS) => self::MASK,
                is_array($value) => self::values($value),
                default => $value,
            };
        }

        return $safe;
    }

    /**
     * Truncate a card number to its last four digits, which is what an operator reconciles
     * against and the most PCI-DSS permits displaying.
     *
     * Digits are extracted first, so a value the gateway already masked ("411111XXXXXX1111",
     * "**** **** **** 1111") truncates to the same four. Anything carrying fewer than four
     * digits — or not a scalar at all — is masked outright rather than partially exposed.
     *
     * @param  mixed  $value  The raw value found under a card-number key.
     */
    private static function pan(mixed $value): string
    {
        if (! is_scalar($value)) {
            return self::MASK;
        }

        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return strlen($digits) < 4 ? self::MASK : self::PAN_MASK.substr($digits, -4);
    }

    /**
     * Whether a header or key name matches the list, ignoring case, dashes and underscores.
     *
     * @param  string  $name  The header or key name as it appeared in the payload.
     * @param  list<string>  $list  The normalised names to match against.
     */
    private static function matches(string $name, array $list): bool
    {
        return in_array(str_replace(['-', '_', '.', ' '], '', strtolower($name)), $list, true);
    }
}
