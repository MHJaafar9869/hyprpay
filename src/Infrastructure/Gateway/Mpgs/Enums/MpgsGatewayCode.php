<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * The `response.gatewayCode` values MPGS returns, mapped to the SDK's PaymentStatus.
 *
 * Used to refine a failed/unknown transaction result into a normalized status —
 * distinguishing an issuer decline (Declined) from a processing error (Failed).
 */
enum MpgsGatewayCode: string
{
    case Approved = 'APPROVED';
    case ApprovedPendingSettlement = 'APPROVED_PENDING_SETTLEMENT';
    case Declined = 'DECLINED';
    case ExpiredCard = 'EXPIRED_CARD';
    case InsufficientFunds = 'INSUFFICIENT_FUNDS';
    case Blocked = 'BLOCKED';
    case ReferredToIssuer = 'REFERRED_TO_ISSUER';
    case DeclinedDoNotContact = 'DECLINED_DO_NOT_CONTACT';
    case DeclinedPaymentPlan = 'DECLINED_PAYMENT_PLAN';
    case NotEnrolled3dSecure = 'NOT_ENROLLED_3D_SECURE';
    case Timeout = 'TIMED_OUT';
    case AcquirerSystemError = 'ACQUIRER_SYSTEM_ERROR';
    case SystemError = 'SYSTEM_ERROR';
    case UnspecifiedFailure = 'UNSPECIFIED_FAILURE';

    /**
     * Map this gateway code onto the SDK's normalized PaymentStatus.
     *
     * Approvals collapse to Captured; issuer/business declines to Declined; timeouts and
     * system errors to Failed.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Approved, self::ApprovedPendingSettlement => PaymentStatus::Captured,
            self::Declined, self::ExpiredCard, self::InsufficientFunds, self::Blocked,
            self::ReferredToIssuer, self::DeclinedDoNotContact, self::DeclinedPaymentPlan,
            self::NotEnrolled3dSecure => PaymentStatus::Declined,
            self::Timeout, self::AcquirerSystemError, self::SystemError, self::UnspecifiedFailure => PaymentStatus::Failed,
        };
    }

    /**
     * Resolve a raw gateway code to a PaymentStatus, defaulting to Failed when the value
     * is null or not a recognized MPGS gateway code.
     *
     * @param  string|null  $code  Raw `response.gatewayCode` string from an MPGS response.
     */
    public static function toPaymentStatusOrFailed(?string $code): PaymentStatus
    {
        return self::tryFrom((string) $code)?->toPaymentStatus() ?? PaymentStatus::Failed;
    }
}
