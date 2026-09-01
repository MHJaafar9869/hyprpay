<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Issuer's standing for the card behind a vaulted payment instrument.
 *
 * Reported by the vault rather than by a charge, so a stored credential can be known to be
 * dead before it is billed — a closed account is why a scheduled rebill would decline
 * permanently, and is worth acting on at rest instead of at charge time.
 */
enum PaymentInstrumentState: string
{
    case Active = 'ACTIVE';
    case Closed = 'CLOSED';

    /**
     * Whether the instrument can still be charged.
     */
    public function isChargeable(): bool
    {
        return $this === self::Active;
    }
}
