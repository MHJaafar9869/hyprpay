<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

/**
 * Value object holding the payer's billing contact and postal details.
 *
 * Every field is optional; the object carries whatever billing information the
 * caller has and is passed into request DTOs (ChargeRequest, PayerAuthEnrollRequest,
 * CheckoutSessionRequest, TokenizeInstrumentRequest) so the gateway can populate its
 * billTo block. Serialises to the CyberSource billTo shape via {@see toArray()}.
 */
final readonly class BillingAddress
{
    /**
     * @param  string|null  $firstName  Payer's given name
     * @param  string|null  $lastName  Payer's family name
     * @param  string|null  $email  Payer's email address
     * @param  string|null  $phoneNumber  Payer's contact phone number
     * @param  string|null  $address1  First line of the street address
     * @param  string|null  $address2  Second line of the street address (suite, unit, etc.)
     * @param  string|null  $locality  City or town
     * @param  string|null  $administrativeArea  State, province, or region
     * @param  string|null  $postalCode  ZIP or postal code
     * @param  string|null  $country  Two-letter ISO country code
     */
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
        public ?string $phoneNumber = null,
        public ?string $address1 = null,
        public ?string $address2 = null,
        public ?string $locality = null,
        public ?string $administrativeArea = null,
        public ?string $postalCode = null,
        public ?string $country = null,
    ) {}

    /**
     * Build the CyberSource billTo shape, including only fields that are present.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $billTo = [];

        if (filled($this->firstName)) {
            $billTo['firstName'] = $this->firstName;
        }

        if (filled($this->lastName)) {
            $billTo['lastName'] = $this->lastName;
        }

        if (filled($this->email)) {
            $billTo['email'] = $this->email;
        }

        if (filled($this->phoneNumber)) {
            $billTo['phoneNumber'] = $this->phoneNumber;
        }

        if (filled($this->address1)) {
            $billTo['address1'] = $this->address1;
        }

        if (filled($this->address2)) {
            $billTo['address2'] = $this->address2;
        }

        if (filled($this->locality)) {
            $billTo['locality'] = $this->locality;
        }

        if (filled($this->administrativeArea)) {
            $billTo['administrativeArea'] = $this->administrativeArea;
        }

        if (filled($this->postalCode)) {
            $billTo['postalCode'] = $this->postalCode;
        }

        if (filled($this->country)) {
            $billTo['country'] = $this->country;
        }

        return $billTo;
    }

    /**
     * Determine whether no billing fields are populated.
     *
     * Returns true when {@see toArray()} yields an empty array, meaning every
     * field was blank and there is nothing to send to the gateway.
     */
    public function isEmpty(): bool
    {
        return blank($this->toArray());
    }
}
