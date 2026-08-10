<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums;

/**
 * The top-level `result` field MPGS returns for a transaction request.
 *
 * Drives whether an operation succeeded; the specific normalized status on success is
 * supplied by the calling operation, and failures are refined via {@see MpgsGatewayCode}.
 */
enum MpgsResult: string
{
    case Success = 'SUCCESS';
    case Pending = 'PENDING';
    case Failure = 'FAILURE';
    case Unknown = 'UNKNOWN';

    /**
     * Whether the request completed successfully.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Success;
    }

    /**
     * Resolve a raw `result` string to a case, defaulting to Unknown when the value is
     * null or unrecognized.
     *
     * @param  string|null  $result  Raw `result` string from an MPGS response.
     */
    public static function fromResponse(?string $result): self
    {
        return self::tryFrom((string) $result) ?? self::Unknown;
    }
}
