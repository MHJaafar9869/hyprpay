<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\PaymentInstrumentState;

/**
 * Result DTO describing a vaulted payment instrument as the vault currently holds it.
 *
 * Where {@see VaultedInstrument} reports the ids produced by tokenising a card, this is the
 * stored record read back: the card's expiry and masked number, whether it is the customer's
 * default, and the issuer's standing for it. That standing is the useful part at rest — a
 * closed account can be spotted and replaced before a scheduled rebill declines on it.
 *
 * The instrument never holds the full card number; the masked suffix comes from the linked
 * instrument identifier.
 */
final readonly class PaymentInstrument
{
    /**
     * @param  string|null  $id  Vault payment-instrument identifier, used to charge the stored credential
     * @param  string|null  $customerId  Vault customer the instrument is stored under, when known
     * @param  string|null  $instrumentIdentifierId  Vault identifier for the underlying card
     * @param  PaymentInstrumentState|null  $state  Issuer's standing for the card (active or closed)
     * @param  bool  $isDefault  Whether this is the customer's default instrument for payments
     * @param  string|null  $expirationMonth  Two-digit expiry month (`MM`)
     * @param  string|null  $expirationYear  Four-digit expiry year (`YYYY`)
     * @param  string|null  $cardType  Gateway card-network code (e.g. `001` Visa, `002` Mastercard)
     * @param  string|null  $maskedNumber  Masked card number from the linked instrument identifier, when present
     * @param  array<string, mixed>  $billTo  Billing address stored with the instrument
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public ?string $id = null,
        public ?string $customerId = null,
        public ?string $instrumentIdentifierId = null,
        public ?PaymentInstrumentState $state = null,
        public bool $isDefault = false,
        public ?string $expirationMonth = null,
        public ?string $expirationYear = null,
        public ?string $cardType = null,
        public ?string $maskedNumber = null,
        public array $billTo = [],
        public array $raw = [],
    ) {}

    /**
     * The stored expiry as `MM/YYYY`, or null when the vault reported neither part.
     */
    public function expiry(): ?string
    {
        if ($this->expirationMonth === null || $this->expirationYear === null) {
            return null;
        }

        return $this->expirationMonth.'/'.$this->expirationYear;
    }

    /**
     * Whether the stored expiry is in the past relative to the given moment (defaults to now).
     *
     * An expired instrument is the most common cause of a permanently failing rebill, so this
     * is worth checking before a scheduled charge rather than after the decline. Returns false
     * when the vault reported no usable expiry — an unknown date is not treated as expired.
     *
     * @param  int|null  $timestamp  Unix timestamp to compare against; defaults to the current time.
     */
    public function isExpired(?int $timestamp = null): bool
    {
        if (! ctype_digit((string) $this->expirationMonth) || ! ctype_digit((string) $this->expirationYear)) {
            return false;
        }

        $now = $timestamp ?? time();
        $endOfExpiryMonth = (int) date('Ym', $now);

        return ((int) $this->expirationYear * 100 + (int) $this->expirationMonth) < $endOfExpiryMonth;
    }
}
