<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Support;

/**
 * Typed coercion helpers for values decoded from gateway JSON responses.
 *
 * Responses arrive as `array<string, mixed>`, so reading a field yields `mixed`.
 * These helpers narrow those mixed values to concrete types at the single point of
 * use, keeping the drivers static-analysis clean at the strictest level without
 * scattering casts and `is_*` guards throughout the code.
 */
final class Value
{
    /**
     * Coerce a value to a string, returning the default when it is not scalar.
     *
     * @param  mixed  $value
     */
    public static function string($value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Coerce a value to a string, or null when it is blank or non-scalar.
     *
     * Mirrors the common `filled($v) ? (string) $v : null` pattern (an empty
     * string, false, or null all yield null).
     *
     * @param  mixed  $value
     */
    public static function nullableString($value): ?string
    {
        return filled($value) && is_scalar($value) ? (string) $value : null;
    }

    /**
     * Coerce a value to an int, returning the default when it is not numeric.
     *
     * @param  mixed  $value
     */
    public static function int($value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Coerce a value to a boolean.
     *
     * @param  mixed  $value
     */
    public static function bool($value): bool
    {
        return (bool) $value;
    }

    /**
     * Narrow a value to a string-keyed array, returning an empty array otherwise.
     *
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    public static function array($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
