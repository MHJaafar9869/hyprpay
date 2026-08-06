<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paymob\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Derives the SDK's normalized payment status from a Paymob transaction object.
 *
 * Paymob does not expose a single status string; the outcome is encoded across the
 * boolean flags on the transaction (success, pending, is_refunded, is_voided). This
 * helper folds those flags onto the gateway-agnostic {@see PaymentStatus}.
 */
final class PaymobTransactionStatus
{
    /**
     * Map a Paymob transaction object's flags to a normalized payment status.
     *
     * @param  array<string, mixed>  $transaction  The Paymob transaction object (the callback `obj` or inquiry response).
     */
    public static function fromTransaction(array $transaction): PaymentStatus
    {
        $success = (bool) ($transaction['success'] ?? false);
        $pending = (bool) ($transaction['pending'] ?? false);
        $refunded = (bool) ($transaction['is_refunded'] ?? false);
        $voided = (bool) ($transaction['is_voided'] ?? false);

        return match (true) {
            $refunded => PaymentStatus::Refunded,
            $voided => PaymentStatus::Voided,
            $success && ! $pending => PaymentStatus::Captured,
            $pending => PaymentStatus::Pending,
            default => PaymentStatus::Declined,
        };
    }
}
