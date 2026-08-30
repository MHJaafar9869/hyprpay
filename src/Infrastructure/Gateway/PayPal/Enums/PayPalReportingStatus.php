<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\PayPal\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * PayPal Transaction Search (Reporting v1) `transaction_status` codes, mapped to the SDK's normalized
 * status.
 *
 * The Reporting API reports a transaction's outcome as a single-letter code rather than the verbose
 * `status` the Orders/Payments APIs return: `S` (successful/settled), `P` (pending), `V` (a reversal —
 * a refund or voided authorization), and `D` (denied). Used only when mapping reporting results into a
 * TransactionSnapshot for reconciliation.
 */
enum PayPalReportingStatus: string
{
    case Success = 'S';
    case Pending = 'P';
    case Reversed = 'V';
    case Denied = 'D';

    /**
     * Map this reporting status code onto the SDK's normalized payment status.
     */
    public function toPaymentStatus(): PaymentStatus
    {
        return match ($this) {
            self::Success => PaymentStatus::Captured,
            self::Pending => PaymentStatus::Pending,
            self::Reversed => PaymentStatus::Voided,
            self::Denied => PaymentStatus::Declined,
        };
    }

    /**
     * Map a raw reporting status code to a normalized status, falling back to Failed.
     *
     * @param  string|null  $status  The raw PayPal reporting `transaction_status` code.
     */
    public static function toPaymentStatusOrFailed(?string $status): PaymentStatus
    {
        return self::tryFrom((string) $status)?->toPaymentStatus() ?? PaymentStatus::Failed;
    }
}
