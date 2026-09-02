<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\ValueObject\BillingAddress;

/**
 * Input DTO for amending a vaulted payment instrument in place.
 *
 * A partial update: only the fields supplied are sent, and everything left null keeps its
 * stored value. The card number itself is never updatable — it belongs to the instrument
 * identifier behind the instrument, so replacing a card means vaulting a new one.
 *
 * The common use is re-dating a card the cardholder has had reissued: updating the expiry on
 * the stored instrument keeps every subscription and stored-credential charge already pointing
 * at it working, without re-collecting the card. Setting {@see $makeDefault} moves the
 * customer's default to this instrument, which is also the prerequisite for deleting whichever
 * instrument is currently the default.
 */
final readonly class UpdatePaymentInstrumentRequest
{
    /**
     * @param  string  $customerId  Vault customer the instrument is stored under
     * @param  string  $paymentInstrumentId  Vault payment-instrument identifier to amend
     * @param  string|null  $expirationMonth  New two-digit expiry month (`MM`)
     * @param  string|null  $expirationYear  New four-digit expiry year (`YYYY`)
     * @param  string|null  $cardType  New gateway card-network code (e.g. `001` Visa)
     * @param  BillingAddress|null  $billTo  Replacement billing address stored with the instrument
     * @param  bool|null  $makeDefault  When true, makes this the customer's default instrument
     */
    public function __construct(
        public string $customerId,
        public string $paymentInstrumentId,
        public ?string $expirationMonth = null,
        public ?string $expirationYear = null,
        public ?string $cardType = null,
        public ?BillingAddress $billTo = null,
        public ?bool $makeDefault = null,
    ) {}
}
