<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout;

/**
 * The normalised reading of a CyberSource authorization that did not settle: the decline reason, whether
 * retrying the same stored credential can ever succeed, whether it was a partial approval that left a hold,
 * and the transaction id (present on a partial approval so the hold can be reversed).
 *
 * Produced by {@see DeclineClassifier}. A permanent outcome should default a plan or stop retrying at once;
 * a non-permanent one is safe to retry (subject to the caller's own attempt ceiling and the card networks'
 * retry limits).
 */
final readonly class DeclineOutcome
{
    public function __construct(
        public string $reason,
        public bool $isPermanent,
        public bool $isPartialAuthorization,
        public ?string $transactionId,
        public string $status,
    ) {}

    /**
     * Whether retrying the same stored credential is worthwhile — true for every decline the classifier
     * did not recognise as permanent (insufficient funds, issuer unavailable, or simply unclassified).
     */
    public function isRetryable(): bool
    {
        return ! $this->isPermanent;
    }
}
