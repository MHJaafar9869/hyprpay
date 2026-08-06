<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Enum;

/**
 * Identifies who initiated a stored-credential (card-on-file) transaction.
 *
 * Distinguishes customer-initiated transactions (CIT) from merchant-initiated
 * transactions (MIT), which network stored-credential rules treat differently
 * (for example recurring or unscheduled merchant charges versus a customer
 * actively completing a purchase).
 */
enum CredentialInitiator: string
{
    case Merchant = 'merchant';
    case Customer = 'customer';

    /**
     * Whether this transaction was initiated by the merchant (MIT) rather than the customer.
     *
     * @return bool True for merchant-initiated transactions, false for customer-initiated ones.
     */
    public function isMerchantInitiated(): bool
    {
        return $this === self::Merchant;
    }
}
