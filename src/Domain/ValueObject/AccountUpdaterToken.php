<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

use Hyprpay\Payments\Domain\Command\CreateAccountUpdaterBatchRequest;

/**
 * A vault token submitted to Account Updater for refresh.
 *
 * The id is whichever TMS token stands for the card — a customer, payment instrument, or
 * instrument identifier — and the networks are asked whether the card behind it has changed.
 * The expiry is optional and only worth sending when the vault's stored dates are known to be
 * stale, since the networks match on it.
 *
 * Built in bulk from plain ids with {@see CreateAccountUpdaterBatchRequest::forTokenIds()}.
 */
final readonly class AccountUpdaterToken
{
    /**
     * @param  string  $id  TMS token id (customer, payment instrument, or instrument identifier)
     * @param  string|null  $expirationMonth  Two-digit expiry month currently on file (`MM`)
     * @param  string|null  $expirationYear  Four-digit expiry year currently on file (`YYYY`)
     */
    public function __construct(
        public string $id,
        public ?string $expirationMonth = null,
        public ?string $expirationYear = null,
    ) {}

    /**
     * The token's fields as the batch request carries them, omitting an expiry not supplied.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'expirationMonth' => $this->expirationMonth,
            'expirationYear' => $this->expirationYear,
        ], filled(...));
    }
}
