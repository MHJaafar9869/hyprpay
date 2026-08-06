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
    case PendingAuthentication = 'PENDING_AUTHENTICATION';
    case Pending = 'PENDING';
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
     * Authorized/partial/pending-review collapse to Authorized; pending and
     * transmitted to Pending; declines and authentication failures to Declined;
     * invalid-request and server errors to Failed.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Authorized, self::PartialAuthorized, self::AuthorizedPendingReview => PaymentStatus::Authorized,
            self::Captured => PaymentStatus::Captured,
            self::Pending, self::PendingAuthentication, self::Transmitted => PaymentStatus::Pending,
            self::Reversed => PaymentStatus::Reversed,
            self::Voided => PaymentStatus::Voided,
            self::Declined, self::AuthenticationFailed => PaymentStatus::Declined,
            self::InvalidRequest, self::ServerError => PaymentStatus::Failed,
        };
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
