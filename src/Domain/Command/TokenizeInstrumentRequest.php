<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\BillingAddress;

/**
 * Input DTO for tokenising (vaulting) a raw card into a reusable payment instrument.
 *
 * Passed to the gateway's tokenise operation to store card details and obtain a vaulted
 * instrument that can later be charged via {@see StoredCredentialChargeRequest}.
 */
final readonly class TokenizeInstrumentRequest
{
    /**
     * @param  string  $cardNumber  Primary account number (PAN) of the card to vault
     * @param  string  $expirationMonth  Two-digit card expiry month (MM)
     * @param  string  $expirationYear  Four-digit card expiry year (YYYY)
     * @param  string|null  $cardType  Optional gateway card-type code (e.g. Visa/Mastercard identifier)
     * @param  BillingAddress|null  $billTo  Optional billing address to store with the instrument
     * @param  string|null  $customerReference  Optional merchant customer reference to associate with the vaulted instrument
     */
    public function __construct(
        public string $cardNumber,
        public string $expirationMonth,
        public string $expirationYear,
        public ?string $cardType = null,
        public ?BillingAddress $billTo = null,
        public ?string $customerReference = null,
    ) {}
}
