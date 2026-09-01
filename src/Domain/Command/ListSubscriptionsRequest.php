<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\Command;

use Hyprpay\Payments\Domain\Enum\SubscriptionStatus;
use Hyprpay\Payments\Domain\Result\SubscriptionPage;

/**
 * Input DTO for listing subscriptions, one page at a time.
 *
 * Every filter is optional and they combine: passing none walks the whole book, while a status
 * of {@see SubscriptionStatus::Delinquent} narrows it to the subscriptions whose last rebill
 * failed. The gateway pages the result, so read {@see SubscriptionPage::hasMore()} and advance
 * {@see $offset} rather than assuming one call returns everything.
 */
final readonly class ListSubscriptionsRequest
{
    /**
     * @param  SubscriptionStatus|null  $status  Return only subscriptions in this lifecycle state
     * @param  string|null  $code  Return only the subscription carrying this subscription code
     * @param  string|null  $customerId  Return only subscriptions billing this vault customer
     * @param  string|null  $orderReference  Return only subscriptions carrying this merchant order/reference number
     * @param  int  $limit  Page size; CyberSource caps it at 100 and defaults to 20
     * @param  int  $offset  Number of records to skip, for paging through the result set
     */
    public function __construct(
        public ?SubscriptionStatus $status = null,
        public ?string $code = null,
        public ?string $customerId = null,
        public ?string $orderReference = null,
        public int $limit = 20,
        public int $offset = 0,
    ) {}

    /**
     * The request for the page after this one, keeping every filter and page size.
     *
     * Pair it with {@see SubscriptionPage::hasMore()} to walk a large result set without
     * recomputing the offset by hand.
     */
    public function nextPage(): self
    {
        return new self(
            status: $this->status,
            code: $this->code,
            customerId: $this->customerId,
            orderReference: $this->orderReference,
            limit: $this->limit,
            offset: $this->offset + $this->limit,
        );
    }
}
