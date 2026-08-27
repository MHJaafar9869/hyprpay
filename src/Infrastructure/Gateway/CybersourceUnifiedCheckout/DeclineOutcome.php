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

    /**
     * A safe, specific message to show the cardholder, derived from the decline reason without ever
     * leaking a raw processor code.
     *
     * Groups the CyberSource reason (and status fallback) into a handful of cardholder-actionable
     * cases — insufficient funds, expired card, failed verification, invalid details, a card that
     * cannot be used, or an issuer decline — defaulting to a generic "could not be completed" when the
     * decline is unclassified or merely a malformed request. Pair it with {@see $reason} if the caller
     * wants to localise its own copy from the code and fall back to this message otherwise.
     */
    public function customerMessage(): string
    {
        return match (true) {
            in_array($this->reason, ['INSUFFICIENT_FUND', 'INSUFFICIENT_FUNDS', 'EXCEEDS_CREDIT_LIMIT'], true) => 'Your card was declined for insufficient funds. Please use another card.',
            $this->reason === 'EXPIRED_CARD' => 'Your card appears to be expired. Please use a different card.',
            in_array($this->reason, ['CV_FAILED', 'AVS_FAILED'], true) => "Your card's security details could not be verified. Please re-check the card number, expiry date and CVV, or use another card.",
            in_array($this->reason, ['INVALID_ACCOUNT', 'INVALID_MERCHANT_CONFIGURATION', 'INVALID_DATA'], true) => 'Your card details were not accepted. Please check them or use a different card.',
            in_array($this->reason, ['STOLEN_LOST_CARD', 'BLACKLISTED_CARD', 'BLOCKED_BY_CARDHOLDER', 'SUSPECTED_FRAUD', 'UNAUTHORIZED_CARD', 'SUSPENDED_ACCOUNT'], true) => 'This card cannot be used for this payment. Please use a different card.',
            in_array($this->reason, ['PROCESSOR_DECLINED', 'CONTACT_PROCESSOR', 'PAYMENT_REFUSED', 'DECISION_PROFILE_REJECT'], true) => 'Your bank declined this card. Please try another card or contact your bank.',
            $this->status === 'DECLINED' => 'Your bank declined this card. Please try another card or contact your bank.',
            $this->status === 'INVALID_REQUEST' => 'Your payment could not be processed. Please try again in a moment.',
            default => 'Your payment could not be completed. Please try again or use a different card.',
        };
    }
}
