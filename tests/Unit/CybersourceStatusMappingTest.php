<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\Enums\CybersourceTransactionStatus;

it('maps a risk-declined authorization to Declined', function (): void {
    expect(CybersourceTransactionStatus::AuthorizedRiskDeclined->toPaymentStatus())
        ->toBe(PaymentStatus::Declined);
});

it('maps a pending-review transaction to Pending', function (): void {
    expect(CybersourceTransactionStatus::PendingReview->toPaymentStatus())
        ->toBe(PaymentStatus::Pending);
});

it('resolves the new raw status strings from the wire', function (): void {
    expect(CybersourceTransactionStatus::toPaymentStatusOrFailed('AUTHORIZED_RISK_DECLINED'))->toBe(PaymentStatus::Declined)
        ->and(CybersourceTransactionStatus::toPaymentStatusOrFailed('PENDING_REVIEW'))->toBe(PaymentStatus::Pending);
});

it('detects a partial authorization that leaves a hold', function (): void {
    expect(CybersourceTransactionStatus::PartialAuthorized->isPartialAuthorization())->toBeTrue()
        ->and(CybersourceTransactionStatus::Authorized->isPartialAuthorization())->toBeFalse();
});

it('detects review or incomplete transactions', function (): void {
    expect(CybersourceTransactionStatus::AuthorizedPendingReview->isReviewOrIncomplete())->toBeTrue()
        ->and(CybersourceTransactionStatus::PendingReview->isReviewOrIncomplete())->toBeTrue()
        ->and(CybersourceTransactionStatus::PendingAuthentication->isReviewOrIncomplete())->toBeTrue()
        ->and(CybersourceTransactionStatus::Authorized->isReviewOrIncomplete())->toBeFalse()
        ->and(CybersourceTransactionStatus::Captured->isReviewOrIncomplete())->toBeFalse();
});
