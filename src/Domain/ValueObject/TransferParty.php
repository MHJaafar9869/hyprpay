<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

use Hyprpay\Payments\Domain\Enum\TransferPartyType;

/**
 * One side of a funds transfer — who is sending, or who is receiving.
 *
 * Card transfers are not anonymous. The networks require the parties to be identified, and for
 * money transfers specifically that identification is a regulatory obligation rather than a
 * gateway preference: name, address, and often date of birth and a government id travel with the
 * transaction so it can be screened against sanctions and AML rules. A transfer that omits them is
 * refused by the network, not merely declined by the issuer.
 *
 * Which fields are mandatory varies by corridor, processor, and the transfer's business
 * application id, so everything here is optional and only what you supply is sent. As a rule, card
 * transfers need at minimum a name and an address; cross-border and higher-value transfers need
 * date of birth and identification too.
 */
final readonly class TransferParty
{
    /**
     * @param  string|null  $firstName  Given name; mandatory for card transfers
     * @param  string|null  $lastName  Family name; mandatory for card transfers
     * @param  string|null  $middleName  Middle name, passed through to the processor untouched
     * @param  string|null  $name  Full name, for an organisation rather than a person
     * @param  TransferPartyType|null  $type  Whether this party is an individual or a business
     * @param  string|null  $address1  Street address; required for card transfers
     * @param  string|null  $address2  Additional address line
     * @param  string|null  $locality  City
     * @param  string|null  $administrativeArea  State or province; required for US and Canadian addresses
     * @param  string|null  $postalCode  Postal code; five or nine digits in the US, alphanumeric elsewhere
     * @param  string|null  $country  Two-letter ISO country code
     * @param  string|null  $email  Email address, including the full domain
     * @param  string|null  $phoneNumber  Phone number, with country code for non-US parties
     * @param  string|null  $dateOfBirth  Date of birth as `YYYYMMDD` — sender only, and required for many money transfers
     * @param  string|null  $referenceNumber  Your own identifier for this party, echoed back for reconciliation
     * @param  string|null  $vatRegistrationNumber  Government-assigned tax identification number
     * @param  array<string, mixed>  $personalIdentification  Government identification (type and number) where the corridor requires it
     */
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $middleName = null,
        public ?string $name = null,
        public ?TransferPartyType $type = null,
        public ?string $address1 = null,
        public ?string $address2 = null,
        public ?string $locality = null,
        public ?string $administrativeArea = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $email = null,
        public ?string $phoneNumber = null,
        public ?string $dateOfBirth = null,
        public ?string $referenceNumber = null,
        public ?string $vatRegistrationNumber = null,
        public array $personalIdentification = [],
    ) {}

    /**
     * The party's fields as a transfer payload carries them, omitting anything not supplied.
     *
     * `$senderOnly` controls the fields the recipient block does not accept — date of birth,
     * reference number, and tax id are sender-side only, and sending them on the recipient would
     * be rejected.
     *
     * @param  bool  $senderOnly  Include the sender-only fields.
     * @return array<string, mixed>
     */
    public function toArray(bool $senderOnly = false): array
    {
        $fields = [
            'firstName' => $this->firstName,
            'middleName' => $this->middleName,
            'lastName' => $this->lastName,
            'name' => $this->name,
            'type' => $this->type?->value,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'locality' => $this->locality,
            'administrativeArea' => $this->administrativeArea,
            'postalCode' => $this->postalCode,
            'country' => $this->country,
            'email' => $this->email,
            'phoneNumber' => $this->phoneNumber,
        ];

        if ($senderOnly) {
            $fields['dateOfBirth'] = $this->dateOfBirth;
            $fields['referenceNumber'] = $this->referenceNumber;
            $fields['vatRegistrationNumber'] = $this->vatRegistrationNumber;
        }

        $party = array_filter($fields, filled(...));

        if (filled($this->personalIdentification)) {
            $party['personalIdentification'] = $this->personalIdentification;
        }

        return $party;
    }
}
