<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\AuthorizeNet\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Infrastructure\Support\Value;

/**
 * Maps Authorize.Net's getTransactionDetails `transactionStatus` values to the SDK's PaymentStatus.
 *
 * Only the settlement-lifecycle statuses the reconciliation flow cares about are enumerated;
 * an unrecognised status resolves to {@see PaymentStatus::Pending} so reconciliation treats it
 * as unresolved rather than guessing a terminal outcome.
 */
enum AuthorizeNetTransactionStatus: string
{
    case AuthorizedPendingCapture = 'authorizedPendingCapture';
    case CapturedPendingSettlement = 'capturedPendingSettlement';
    case SettledSuccessfully = 'settledSuccessfully';
    case Voided = 'voided';
    case RefundPendingSettlement = 'refundPendingSettlement';
    case RefundSettledSuccessfully = 'refundSettledSuccessfully';
    case Declined = 'declined';
    case Expired = 'expired';
    case GeneralError = 'generalError';
    case FDSPendingReview = 'FDSPendingReview';
    case FDSAuthorizedPendingReview = 'FDSAuthorizedPendingReview';

    /**
     * Resolve the PaymentStatus for a getTransactionDetails `transaction` object.
     *
     * @param  array<string, mixed>  $transaction
     */
    public static function fromTransaction(array $transaction): PaymentStatus
    {
        $status = Value::nullableString($transaction['transactionStatus'] ?? null);
        $case = $status !== null ? self::tryFrom($status) : null;

        return $case?->toPaymentStatus() ?? PaymentStatus::Pending;
    }

    /**
     * Translate this Authorize.Net status into the SDK's normalized PaymentStatus.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::AuthorizedPendingCapture, self::FDSAuthorizedPendingReview => PaymentStatus::Authorized,
            self::CapturedPendingSettlement, self::SettledSuccessfully => PaymentStatus::Captured,
            self::Voided => PaymentStatus::Voided,
            self::RefundPendingSettlement, self::RefundSettledSuccessfully => PaymentStatus::Refunded,
            self::Declined => PaymentStatus::Declined,
            self::Expired, self::GeneralError => PaymentStatus::Failed,
            self::FDSPendingReview => PaymentStatus::Pending,
        };
    }
}
