<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

/**
 * Value object identifying the customer behind a payment.
 *
 * Every field is optional; the object carries whatever customer identity the caller
 * has and is embedded in request DTOs (such as ChargeRequest) so the gateway can link
 * the transaction to a customer profile.
 */
final readonly class Customer
{
    /**
     * @param  string|null  $reference  Merchant-side customer identifier
     * @param  string|null  $email  Customer's email address
     * @param  string|null  $firstName  Customer's given name
     * @param  string|null  $lastName  Customer's family name
     */
    public function __construct(
        public ?string $reference = null,
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
    ) {}
}
