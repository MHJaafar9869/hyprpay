<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Enum\BinLookupStatus;
use Hyprpay\Payments\Domain\Enum\CardFundingSource;
use Hyprpay\Payments\Domain\Enum\CardPlatform;
use Hyprpay\Payments\Domain\Enum\CybersourceCardNetwork;

/**
 * Result DTO describing what a card actually is, from a BIN lookup.
 *
 * Everything here is known *before* an authorization, which is the point: routing, surcharging,
 * whether to offer installments, and whether 3-D Secure is worth attempting are all decisions
 * that have to be made in advance.
 *
 * Read {@see $status} first. A lookup that did not resolve to a single card range leaves the
 * attributes untrustworthy or absent, and "unknown" is never grounds for refusing a payment on
 * its own — fall back to charging normally rather than blocking the customer.
 *
 * The networks publish far more BIN attributes than are modelled as properties here, and the set
 * grows; the whole `features` block is kept verbatim on {@see $features} and reachable by name
 * through {@see feature()}, so a newly-published attribute needs no SDK change to read.
 */
final readonly class BinLookupResult
{
    /**
     * @param  BinLookupStatus|null  $status  Whether the lookup resolved to one card range
     * @param  string|null  $cardType  CyberSource three-digit network code (e.g. `001` Visa)
     * @param  string|null  $brandName  Card brand as the networks name it (e.g. `VISA`)
     * @param  string|null  $currency  ISO currency the card bills in, when known
     * @param  int|null  $maxLength  Maximum length of the account number
     * @param  string|null  $credentialType  Whether the credential inspected was a `PAN` or a `TOKEN`
     * @param  CardFundingSource|null  $fundingSource  How the account is funded (credit, debit, prepaid…)
     * @param  string|null  $fundingSubType  For prepaid cards, whether it is reloadable
     * @param  CardPlatform|null  $platform  Whether the card was issued to a person or an organisation
     * @param  string|null  $cardProduct  Issuer product name (e.g. `Visa Infinite`)
     * @param  string|null  $issuerName  Issuing bank
     * @param  string|null  $issuerCountry  Two-letter ISO country of the issuer
     * @param  string|null  $accountPrefix  Leading digits of the account number, truncated per PCI-DSS
     * @param  string|null  $issuerPhone  Issuer's cardholder service number
     * @param  array<string, mixed>  $features  The full BIN feature block, verbatim
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public ?BinLookupStatus $status = null,
        public ?string $cardType = null,
        public ?string $brandName = null,
        public ?string $currency = null,
        public ?int $maxLength = null,
        public ?string $credentialType = null,
        public ?CardFundingSource $fundingSource = null,
        public ?string $fundingSubType = null,
        public ?CardPlatform $platform = null,
        public ?string $cardProduct = null,
        public ?string $issuerName = null,
        public ?string $issuerCountry = null,
        public ?string $accountPrefix = null,
        public ?string $issuerPhone = null,
        public array $features = [],
        public array $raw = [],
    ) {}

    /**
     * Whether the lookup resolved to a single card range, so the attributes describe the real card.
     */
    public function isResolved(): bool
    {
        return $this->status?->isResolved() === true;
    }

    /**
     * The card network as a typed enum, resolved from the numeric type code and falling back to
     * the brand name — so branching on the network is one match, whatever shape the gateway used.
     *
     * Null for a network this SDK does not model, which is not an error.
     */
    public function network(): ?CybersourceCardNetwork
    {
        return CybersourceCardNetwork::resolve($this->cardType)
            ?? CybersourceCardNetwork::resolve($this->brandName);
    }

    /**
     * Read any BIN feature by its gateway name, including ones this DTO does not model.
     *
     * @param  string  $name  Feature key as the gateway spells it (e.g. `fleetCard`).
     * @param  mixed  $default  Returned when the gateway did not report the feature.
     */
    public function feature(string $name, mixed $default = null): mixed
    {
        return $this->features[$name] ?? $default;
    }

    /**
     * Whether a feature the gateway reports as a boolean is on. Unreported features are false,
     * so an absent attribute never reads as an entitlement the card does not have.
     *
     * @param  string  $name  Feature key as the gateway spells it.
     */
    public function hasFeature(string $name): bool
    {
        return $this->feature($name) === true;
    }

    /**
     * Whether the card supports 3-D Secure, so attempting authentication is worthwhile.
     */
    public function supports3ds(): bool
    {
        return $this->hasFeature('threeDSSupport');
    }

    /**
     * Whether the card is eligible for standing instructions — recurring or subscription billing.
     */
    public function supportsRecurring(): bool
    {
        return $this->hasFeature('siEligible');
    }

    /**
     * Whether the card is eligible for issuer-funded installments (EMI), so an installment
     * option is worth offering at checkout.
     */
    public function supportsInstallments(): bool
    {
        return $this->hasFeature('emiEligible');
    }

    /**
     * Whether the card is enabled for e-commerce at all.
     */
    public function supportsEcommerce(): bool
    {
        return $this->hasFeature('ecomEnabled');
    }

    /**
     * Whether a transaction on this card can qualify for Level 2 or Level 3 interchange, which
     * is what makes supplying the extra line-item and tax data worthwhile.
     */
    public function qualifiesForCommercialRates(): bool
    {
        if ($this->hasFeature('commercialCardLevel2')) {
            return true;
        }

        return $this->hasFeature('commercialCardLevel3');
    }
}
