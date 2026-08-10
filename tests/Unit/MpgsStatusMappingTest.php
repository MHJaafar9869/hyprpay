<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsGatewayCode;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsOrderStatus;
use Hyprpay\Payments\Infrastructure\Gateway\Mpgs\Enums\MpgsResult;

it('maps gateway codes to a normalized status', function (): void {
    expect(MpgsGatewayCode::toPaymentStatusOrFailed('APPROVED'))->toBe(PaymentStatus::Captured)
        ->and(MpgsGatewayCode::toPaymentStatusOrFailed('DECLINED'))->toBe(PaymentStatus::Declined)
        ->and(MpgsGatewayCode::toPaymentStatusOrFailed('INSUFFICIENT_FUNDS'))->toBe(PaymentStatus::Declined)
        ->and(MpgsGatewayCode::toPaymentStatusOrFailed('TIMED_OUT'))->toBe(PaymentStatus::Failed)
        ->and(MpgsGatewayCode::toPaymentStatusOrFailed('SOMETHING_ELSE'))->toBe(PaymentStatus::Failed)
        ->and(MpgsGatewayCode::toPaymentStatusOrFailed(null))->toBe(PaymentStatus::Failed);
});

it('maps order statuses to a normalized status', function (): void {
    expect(MpgsOrderStatus::toPaymentStatusOrFailed('CAPTURED'))->toBe(PaymentStatus::Captured)
        ->and(MpgsOrderStatus::toPaymentStatusOrFailed('AUTHORIZED'))->toBe(PaymentStatus::Authorized)
        ->and(MpgsOrderStatus::toPaymentStatusOrFailed('PARTIALLY_REFUNDED'))->toBe(PaymentStatus::Refunded)
        ->and(MpgsOrderStatus::toPaymentStatusOrFailed('CANCELLED'))->toBe(PaymentStatus::Voided)
        ->and(MpgsOrderStatus::toPaymentStatusOrFailed('DECLINED'))->toBe(PaymentStatus::Declined)
        ->and(MpgsOrderStatus::toPaymentStatusOrFailed('UNRECOGNISED'))->toBe(PaymentStatus::Failed);
});

it('reads the transaction result, defaulting to unknown', function (): void {
    expect(MpgsResult::fromResponse('SUCCESS')->isSuccessful())->toBeTrue()
        ->and(MpgsResult::fromResponse('FAILURE')->isSuccessful())->toBeFalse()
        ->and(MpgsResult::fromResponse(null))->toBe(MpgsResult::Unknown);
});
