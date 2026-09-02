<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * How the account behind a card is funded.
 *
 * Drives decisions that have to be made before authorizing: surcharging rules differ by
 * funding source in many markets, prepaid cards are the usual source of partial approvals,
 * and debit often routes differently from credit.
 */
enum CardFundingSource: string
{
    case Credit = 'CREDIT';
    case Debit = 'DEBIT';
    case Prepaid = 'PREPAID';
    case DeferredDebit = 'DEFERRED DEBIT';
    case Charge = 'CHARGE';

    /**
     * Human-readable display name for the funding source.
     */
    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit',
            self::Debit => 'Debit',
            self::Prepaid => 'Prepaid',
            self::DeferredDebit => 'Deferred debit',
            self::Charge => 'Charge',
        };
    }

    /**
     * Whether a partial approval is a realistic outcome for this funding source.
     *
     * A prepaid card carries a fixed balance and is the common cause of a PARTIAL_AUTHORIZED
     * response, which holds funds without settling the charge.
     */
    public function canPartiallyApprove(): bool
    {
        return $this === self::Prepaid;
    }
}
