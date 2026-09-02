<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Enum\SubscriptionStatus;
use Hyprpay\Payments\Domain\Result\PaymentResult;
use Hyprpay\Payments\Domain\Result\SubscriptionResult;
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

it('maps decline reasons to safe, specific customer messages', function (string $reason, string $status, string $expected): void {
    $outcome = DeclineClassifier::classify(array_filter([
        'status' => $status,
        'errorInformation' => $reason !== '' ? ['reason' => $reason] : null,
    ]));

    expect($outcome->customerMessage())->toBe($expected);
})->with([
    'insufficient funds' => ['INSUFFICIENT_FUND', 'DECLINED', 'Your card was declined for insufficient funds. Please use another card.'],
    'expired card' => ['EXPIRED_CARD', 'DECLINED', 'Your card appears to be expired. Please use a different card.'],
    'blocked card' => ['STOLEN_LOST_CARD', 'DECLINED', 'This card cannot be used for this payment. Please use a different card.'],
    'issuer decline (reason)' => ['PAYMENT_REFUSED', 'DECLINED', 'Your bank declined this card. Please try another card or contact your bank.'],
    'issuer decline (status only)' => ['', 'DECLINED', 'Your bank declined this card. Please try another card or contact your bank.'],
    'malformed request' => ['', 'INVALID_REQUEST', 'Your payment could not be processed. Please try again in a moment.'],
    'unclassified' => ['', '', 'Your payment could not be completed. Please try again or use a different card.'],
]);

it('classifies a declined subscription create from its raw recurring billing response', function (): void {
    $result = new SubscriptionResult(
        success: false,
        status: SubscriptionStatus::Failed,
        subscriptionId: 'sub_1',
        requestStatus: 'DECLINED',
        raw: [
            'id' => 'sub_1',
            'status' => 'DECLINED',
            'errorInformation' => ['reason' => 'EXPIRED_CARD'],
            'subscriptionInformation' => ['status' => 'FAILED'],
        ],
    );

    $outcome = DeclineClassifier::fromResult($result);

    expect($outcome->isPermanent)->toBeTrue()
        ->and($outcome->isRetryable())->toBeFalse()
        ->and($outcome->reason)->toBe('EXPIRED_CARD')
        ->and($outcome->transactionId)->toBe('sub_1')
        ->and($outcome->customerMessage())->toBe('Your card appears to be expired. Please use a different card.');
});

it('treats a transiently declined subscription create as worth retrying', function (): void {
    $result = new SubscriptionResult(
        success: false,
        status: SubscriptionStatus::Failed,
        requestStatus: 'DECLINED',
        raw: [
            'status' => 'DECLINED',
            'errorInformation' => ['reason' => 'INSUFFICIENT_FUND'],
            'subscriptionInformation' => ['status' => 'FAILED'],
        ],
    );

    expect(DeclineClassifier::fromResult($result)->isRetryable())->toBeTrue();
});

it('classifies a failed subscription rebill straight from a verified webhook payload', function (): void {
    $outcome = DeclineClassifier::classify([
        'id' => 'PAY9',
        'status' => 'DECLINED',
        'errorInformation' => ['reason' => 'GENERIC_DECLINE'],
        'processorInformation' => ['merchantAdvice' => ['code' => '01']],
    ]);

    expect($outcome->isPermanent)->toBeTrue()
        ->and($outcome->isRetryable())->toBeFalse();
});
