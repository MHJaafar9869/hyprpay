<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

/**
 * Result DTO holding one page of a customer's vaulted payment instruments.
 *
 * The vault pages its instruments, so this carries the page's records alongside the total the
 * customer holds and the window that produced them — enough to walk a customer with more cards
 * than one page returns.
 */
final readonly class PaymentInstrumentPage
{
    /**
     * @param  list<PaymentInstrument>  $instruments  The instruments on this page
     * @param  int  $totalCount  Total instruments the customer holds, across every page
     * @param  int  $offset  Number of records skipped before this page
     * @param  int  $limit  Page size the vault was asked for
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public array $instruments,
        public int $totalCount = 0,
        public int $offset = 0,
        public int $limit = 0,
        public array $raw = [],
    ) {}

    /**
     * Whether this page returned no instruments at all.
     */
    public function isEmpty(): bool
    {
        return $this->instruments === [];
    }

    /**
     * How many instruments this page holds.
     */
    public function count(): int
    {
        return count($this->instruments);
    }

    /**
     * Whether the customer holds more instruments than this page returned.
     *
     * False on an empty page, so a walk always terminates.
     */
    public function hasMore(): bool
    {
        return ! $this->isEmpty() && ($this->offset + $this->count()) < $this->totalCount;
    }

    /**
     * The customer's default instrument on this page, or null when none is flagged.
     */
    public function default(): ?PaymentInstrument
    {
        foreach ($this->instruments as $instrument) {
            if ($instrument->isDefault) {
                return $instrument;
            }
        }

        return null;
    }
}
