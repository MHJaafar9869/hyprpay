<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Outcome of a BIN lookup.
 *
 * Only a completed lookup describes one card range. `Multiple` means the credential matched
 * more than one range and the attributes cannot be trusted to describe the actual card, and
 * `NoMatch` means the networks know nothing about it — both are "unknown", not "declined", so
 * neither is grounds for refusing a payment on its own.
 */
enum BinLookupStatus: string
{
    case Completed = 'COMPLETED';
    case Multiple = 'MULTIPLE';
    case NoMatch = 'NO MATCH';

    /**
     * Whether the lookup resolved to a single, trustworthy card range.
     */
    public function isResolved(): bool
    {
        return $this === self::Completed;
    }
}
