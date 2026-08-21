<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\DeclineClassifier;

it('marks a permanent decline reason as not retryable', function (): void {
    $outcome = DeclineClassifier::classify([
        'id' => 'PAY1',
        'status' => 'DECLINED',
        'errorInformation' => ['reason' => 'EXPIRED_CARD'],
    ]);

    expect($outcome->isPermanent)->toBeTrue()
        ->and($outcome->isRetryable())->toBeFalse()
        ->and($outcome->reason)->toBe('EXPIRED_CARD')
        ->and($outcome->transactionId)->toBe('PAY1');
});

it('treats a merchant-advice "do not try again" code as permanent even with an unknown reason', function (): void {
    $outcome = DeclineClassifier::classify([
        'status' => 'DECLINED',
        'errorInformation' => ['reason' => 'GENERIC_DECLINE'],
        'processorInformation' => ['merchantAdvice' => ['code' => '03']],
    ]);

    expect($outcome->isPermanent)->toBeTrue()
        ->and($outcome->isRetryable())->toBeFalse();
});

it('treats a transient decline (insufficient funds) as retryable', function (): void {
    $outcome = DeclineClassifier::classify([
        'status' => 'DECLINED',
        'errorInformation' => ['reason' => 'INSUFFICIENT_FUND'],
        'processorInformation' => ['merchantAdvice' => ['code' => '02']],
    ]);

    expect($outcome->isPermanent)->toBeFalse()
        ->and($outcome->isRetryable())->toBeTrue()
        ->and($outcome->reason)->toBe('INSUFFICIENT_FUND');
});

it('defaults an unclassified decline to retryable so it never prematurely gives up', function (): void {
    $outcome = DeclineClassifier::classify(['status' => 'DECLINED']);

    expect($outcome->isPermanent)->toBeFalse()
        ->and($outcome->isRetryable())->toBeTrue()
        ->and($outcome->reason)->toBe('DECLINED');
});

it('falls back to UNKNOWN when neither a reason nor a status is present', function (): void {
    $outcome = DeclineClassifier::classify([]);

    expect($outcome->reason)->toBe('UNKNOWN')
        ->and($outcome->status)->toBe('')
        ->and($outcome->transactionId)->toBeNull();
});

it('flags a partial approval that left a hold, carrying the transaction id', function (): void {
    $outcome = DeclineClassifier::classify([
        'id' => 'PAY2',
        'status' => 'PARTIAL_AUTHORIZED',
    ]);

    expect($outcome->isPartialAuthorization)->toBeTrue()
        ->and($outcome->transactionId)->toBe('PAY2');
});

it('classifies straight from a PaymentResult raw response', function (): void {
    $result = new PaymentResult(
        success: false,
        status: PaymentStatus::Declined,
        transactionId: 'PAY3',
        raw: ['id' => 'PAY3', 'status' => 'DECLINED', 'errorInformation' => ['reason' => 'STOLEN_LOST_CARD']],
    );

    $outcome = DeclineClassifier::fromResult($result);

    expect($outcome->isPermanent)->toBeTrue()
        ->and($outcome->reason)->toBe('STOLEN_LOST_CARD');
});
