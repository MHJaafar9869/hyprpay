<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

/**
 * Result DTO returned after tokenising (vaulting) a card.
 *
 * Returned by the gateway's tokenise operation, exposing the vault identifiers created
 * for the card so it can later be charged as a stored credential.
 */
final readonly class VaultedInstrument
{
    /**
     * @param  bool  $success  Whether the instrument was vaulted successfully
     * @param  string|null  $instrumentIdentifierId  Vault identifier for the underlying card (instrument identifier)
     * @param  string|null  $customerId  Vault customer identifier the instrument was stored under
     * @param  string|null  $paymentInstrumentId  Vault payment-instrument identifier used to charge the stored credential
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public bool $success,
        public ?string $instrumentIdentifierId = null,
        public ?string $customerId = null,
        public ?string $paymentInstrumentId = null,
        public array $raw = [],
    ) {}
}
