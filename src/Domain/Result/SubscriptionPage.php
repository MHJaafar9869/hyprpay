<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Result;

use Hyprpay\Payments\Domain\Command\ListSubscriptionsRequest;

/**
 * Result DTO holding one page of subscriptions from a list call.
 *
 * The gateway pages its subscription book, so this carries the page's records alongside the
 * total the filter matched and the window that produced them — enough to tell "20 of 340" from
 * "the last 20". Walk the rest with {@see hasMore()} and
 * {@see ListSubscriptionsRequest::nextPage()}.
 */
final readonly class SubscriptionPage
{
    /**
     * @param  list<SubscriptionResult>  $subscriptions  The subscriptions on this page, in the order the gateway returned them
     * @param  int  $totalCount  Total number of subscriptions matching the filter, across every page
     * @param  int  $offset  Number of records skipped before this page
     * @param  int  $limit  Page size the gateway was asked for
     * @param  array<string, mixed>  $raw  Raw gateway response payload
     */
    public function __construct(
        public array $subscriptions,
        public int $totalCount = 0,
        public int $offset = 0,
        public int $limit = 0,
        public array $raw = [],
    ) {}

    /**
     * Whether this page returned no subscriptions at all.
     */
    public function isEmpty(): bool
    {
        return $this->subscriptions === [];
    }

    /**
     * How many subscriptions this page holds.
     */
    public function count(): int
    {
        return count($this->subscriptions);
    }

    /**
     * Whether more subscriptions matched the filter than this page returned.
     *
     * False on an empty page, so a walk always terminates even if the gateway reports a total
     * larger than the records it can actually serve.
     */
    public function hasMore(): bool
    {
        return ! $this->isEmpty() && ($this->offset + $this->count()) < $this->totalCount;
    }
}
