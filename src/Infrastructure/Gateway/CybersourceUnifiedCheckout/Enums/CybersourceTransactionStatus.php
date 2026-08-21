<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Raw transaction status strings returned by the CyberSource REST API, with a
 * mapping to the SDK's normalized PaymentStatus.
 *
 * Covers authorization, capture, reversal/void, decline, and error outcomes.
 * Use toPaymentStatus() / toPaymentStatusOrFailed() to translate a CyberSource
 * status into the gateway-agnostic PaymentStatus the rest of the SDK consumes.
 */
enum CybersourceTransactionStatus: string
{
    case Authorized = 'AUTHORIZED';
    case PartialAuthorized = 'PARTIAL_AUTHORIZED';
    case AuthorizedPendingReview = 'AUTHORIZED_PENDING_REVIEW';
    case AuthorizedRiskDeclined = 'AUTHORIZED_RISK_DECLINED';
    case PendingAuthentication = 'PENDING_AUTHENTICATION';
    case Pending = 'PENDING';
    case PendingReview = 'PENDING_REVIEW';
    case Transmitted = 'TRANSMITTED';
    case Captured = 'CAPTURED';
    case Reversed = 'REVERSED';
    case Voided = 'VOIDED';
    case Declined = 'DECLINED';
    case AuthenticationFailed = 'AUTHENTICATION_FAILED';
    case InvalidRequest = 'INVALID_REQUEST';
    case ServerError = 'SERVER_ERROR';

    /**
     * Maps this CyberSource status onto the SDK's normalized PaymentStatus.
     *
     * Authorized, partial-authorized, and authorized-pending-review collapse to Authorized; pending,
     * pending-review, pending-authentication, and transmitted to Pending; declines, authentication
     * failures, and risk-declined authorizations to Declined; invalid-request and server errors to
     * Failed. Note that PARTIAL_AUTHORIZED and the pending-review states still read as non-error here —
     * use isPartialAuthorization() / isReviewOrIncomplete() to tell a settled charge apart from a
     * stranded hold or a transaction still held for review.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Authorized, self::PartialAuthorized, self::AuthorizedPendingReview => PaymentStatus::Authorized,
            self::Captured => PaymentStatus::Captured,
            self::Pending, self::PendingReview, self::PendingAuthentication, self::Transmitted => PaymentStatus::Pending,
            self::Reversed => PaymentStatus::Reversed,
            self::Voided => PaymentStatus::Voided,
            self::Declined, self::AuthenticationFailed, self::AuthorizedRiskDeclined => PaymentStatus::Declined,
            self::InvalidRequest, self::ServerError => PaymentStatus::Failed,
        };
    }

    /**
     * A partial approval: the issuer authorized less than the requested amount (e.g. a prepaid card with
     * a low balance). It holds funds but does not settle the charge, so the hold must be released with
     * reverseAuthorization() rather than captured or blindly retried. The normalized PaymentStatus reads
     * as Authorized, so callers must check this to avoid stranding the hold.
     */
    public function isPartialAuthorization(): bool
    {
        return $this === self::PartialAuthorized;
    }

    /**
     * A transaction that is neither a confirmed settlement nor a terminal failure: it is held for a
     * Decision Manager review or awaiting payer authentication. Poll getTransaction() or await the
     * webhook rather than marking the order paid or failed.
     */
    public function isReviewOrIncomplete(): bool
    {
        return in_array($this, [
            self::AuthorizedPendingReview,
            self::PendingReview,
            self::PendingAuthentication,
        ], true);
    }

    /**
     * Resolves a raw status string to a PaymentStatus, defaulting to Failed when
     * the value is null or not a recognized CyberSource status.
     *
     * @param  string|null  $status  Raw status string from a CyberSource response or webhook.
     */
    public static function toPaymentStatusOrFailed(?string $status): PaymentStatus
    {
        return self::tryFrom((string) $status)?->toPaymentStatus() ?? PaymentStatus::Failed;
    }
}
