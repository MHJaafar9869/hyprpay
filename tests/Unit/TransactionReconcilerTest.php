<?php

declare(strict_types=1);

use Hyprpay\Payments\Application\PaymentGatewayFactory;
use Hyprpay\Payments\Application\TransactionReconciler;
use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;

/**
 * Build a reconciler whose factory drives real gateways over the given fake client.
 */
function transactionReconciler(FakeHttpClient $http): TransactionReconciler
{
    return new TransactionReconciler(
        new PaymentGatewayFactory($http, recordingResolver(testCredentials())),
    );
}

it('reconciles several transactions into outcomes keyed by the requested id, in order', function (): void {
    $http = (new FakeHttpClient)
        ->queueJson(['orderStatus' => 'PAID', 'fawryRefNumber' => 'F-1', 'merchantRefNumber' => 'ORD-1'])
        ->queueJson(['orderStatus' => 'PAID', 'fawryRefNumber' => 'F-2', 'merchantRefNumber' => 'ORD-2']);

    $outcomes = transactionReconciler($http)->reconcile(GatewayName::Fawry, ['ORD-1', 'ORD-2']);

    expect($outcomes)->toHaveCount(2)
        ->and($outcomes[0]->reconciled())->toBeTrue()
        ->and($outcomes[0]->transactionId)->toBe('ORD-1')
        ->and($outcomes[0]->snapshot?->status)->toBe(PaymentStatus::Captured)
        ->and($outcomes[0]->snapshot?->transactionId)->toBe('F-1')
        ->and($outcomes[1]->transactionId)->toBe('ORD-2')
        ->and($outcomes[1]->snapshot?->transactionId)->toBe('F-2')
        ->and($http->requestCount())->toBe(2);
});

it('captures a gateway failure as an error outcome without aborting the batch', function (): void {
    $http = (new FakeHttpClient)
        ->queueBody('{"reason":"boom"}', 500) // first lookup fails at transport level
        ->queueJson(['orderStatus' => 'PAID', 'fawryRefNumber' => 'F-9', 'merchantRefNumber' => 'ORD-9']);

    $outcomes = transactionReconciler($http)->reconcile(GatewayName::Fawry, ['ORD-BAD', 'ORD-9']);

    expect($outcomes)->toHaveCount(2)
        ->and($outcomes[0]->reconciled())->toBeFalse()
        ->and($outcomes[0]->transactionId)->toBe('ORD-BAD')
        ->and($outcomes[0]->snapshot)->toBeNull()
        ->and($outcomes[0]->error)->not->toBeNull()
        ->and($outcomes[1]->reconciled())->toBeTrue()
        ->and($outcomes[1]->snapshot?->transactionId)->toBe('F-9');
});

it('returns an empty result when given no transaction ids', function (): void {
    $outcomes = transactionReconciler(new FakeHttpClient)->reconcile(GatewayName::Fawry, []);

    expect($outcomes)->toBe([]);
});
