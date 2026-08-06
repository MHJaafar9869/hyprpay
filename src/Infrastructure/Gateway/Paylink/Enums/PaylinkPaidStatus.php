<?php

declare(strict_types=1);

namespace Hyprpay\Payments\Infrastructure\Gateway\Paylink\Enums;

use Hyprpay\Payments\Domain\Enum\PaymentStatus;

/**
 * Maps PayLink invoice paid/authorization statuses to the SDK's normalized status.
 *
 * PayLink returns an uppercase status string (PAID, AUTHORIZED, UNPAID, VOIDED,
 * REFUNDED, …) on payment responses and webhooks; this folds those onto the
 * gateway-agnostic {@see PaymentStatus}.
 */
final class PaylinkPaidStatus
{
    /**
     * Map a raw PayLink status string to a normalized payment status.
     *
     * @param  string|null  $status  The PayLink paid_status / invoice_status value.
     */
    public static function toPaymentStatus(?string $status): PaymentStatus
    {
        return match (strtoupper((string) $status)) {
            'PAID' => PaymentStatus::Captured,
            'AUTHORIZED' => PaymentStatus::Authorized,
            'UNPAID', 'PENDING' => PaymentStatus::Pending,
            'VOIDED' => PaymentStatus::Voided,
            'REVERSED' => PaymentStatus::Reversed,
            'REFUNDED', 'PARTIALLY_REFUNDED' => PaymentStatus::Refunded,
            'DECLINED' => PaymentStatus::Declined,
            default => PaymentStatus::Failed,
        };
    }
}
