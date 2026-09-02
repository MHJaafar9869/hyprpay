<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Domain\ValueObject;

use Hyprpay\Payments\Domain\Enum\WebhookRetryAlgorithm;

/**
 * How the gateway retries a webhook your endpoint failed to accept.
 *
 * The algorithm matters more than it looks. With a first retry of 10 minutes and an interval of
 * 30, {@see WebhookRetryAlgorithm::Arithmetic} retries at 10, 40, and 70 minutes, while
 * {@see WebhookRetryAlgorithm::Geometric} retries at 10, 300, and 9,000 — a difference between
 * "later today" and "in six days".
 *
 * {@see $deactivateOnFailure} decides what happens when a sequence is exhausted: with it on, the
 * subscription suspends and queues new notifications until your health-check URL recovers, then
 * delivers the backlog; with it off, each notification exhausts its own retries independently and
 * the subscription stays active.
 */
final readonly class WebhookRetryPolicy
{
    /**
     * @param  WebhookRetryAlgorithm  $algorithm  How the delay between retries grows
     * @param  int|null  $firstRetry  Minutes to wait before the first retry
     * @param  int|null  $interval  Interval between retries, in minutes
     * @param  int|null  $numberOfRetries  Retries per sequence
     * @param  bool|null  $deactivateOnFailure  Suspend the subscription when a sequence fails, queueing until it recovers
     * @param  int|null  $repeatSequenceCount  How many times to repeat the whole retry sequence
     * @param  int|null  $repeatSequenceWaitTime  Minutes to wait between repeated sequences
     */
    public function __construct(
        public WebhookRetryAlgorithm $algorithm = WebhookRetryAlgorithm::Arithmetic,
        public ?int $firstRetry = null,
        public ?int $interval = null,
        public ?int $numberOfRetries = null,
        public ?bool $deactivateOnFailure = null,
        public ?int $repeatSequenceCount = null,
        public ?int $repeatSequenceWaitTime = null,
    ) {}

    /**
     * The policy as the subscription payload carries it, omitting anything not set so the
     * gateway's own defaults apply.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'algorithm' => $this->algorithm->value,
            'firstRetry' => $this->firstRetry,
            'interval' => $this->interval,
            'numberOfRetries' => $this->numberOfRetries,
            'deactivateFlag' => $this->deactivateOnFailure === null ? null : ($this->deactivateOnFailure ? 'true' : 'false'),
            'repeatSequenceCount' => $this->repeatSequenceCount,
            'repeatSequenceWaitTime' => $this->repeatSequenceWaitTime,
        ], static fn (int|string|null $value): bool => $value !== null);
    }
}
