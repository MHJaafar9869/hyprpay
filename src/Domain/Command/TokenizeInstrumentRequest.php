<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\BillingAddress;

/**
 * Input DTO for tokenising (vaulting) a card into a reusable payment instrument.
 *
 * Passed to the gateway's tokenise operation to store the card and obtain a vaulted
 * instrument that can later be charged via {@see StoredCredentialChargeRequest}. Supply
 * either the raw card fields, or a {@see $transientToken} (a browser-tokenised nonce such
 * as Authorize.Net Accept.js opaque data) to vault without the PAN touching the server.
 */
final readonly class TokenizeInstrumentRequest
{
    /**
     * @param  string  $cardNumber  Primary account number (PAN); leave blank when vaulting from a transient token
     * @param  string  $expirationMonth  Two-digit card expiry month (MM); blank when using a transient token
     * @param  string  $expirationYear  Four-digit card expiry year (YYYY); blank when using a transient token
     * @param  string|null  $cardType  Optional gateway card-type code (e.g. Visa/Mastercard identifier)
     * @param  BillingAddress|null  $billTo  Optional billing address to store with the instrument
     * @param  string|null  $customerReference  Optional merchant customer reference to associate with the vaulted instrument
     * @param  string|null  $transientToken  Browser-tokenised payment nonce (e.g. Accept.js opaque data) to vault without handling the raw card
     */
    public function __construct(
        public string $cardNumber = '',
        public string $expirationMonth = '',
        public string $expirationYear = '',
        public ?string $cardType = null,
        public ?BillingAddress $billTo = null,
        public ?string $customerReference = null,
        public ?string $transientToken = null,
    ) {}
}
