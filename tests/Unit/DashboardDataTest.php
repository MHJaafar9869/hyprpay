<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Domain\ValueObject\Money;
use Hyprpay\Payments\Infrastructure\Dashboard\DashboardData;
use Hyprpay\Payments\Infrastructure\Gateway\CybersourceUnifiedCheckout\CybersourceUnifiedCheckoutGateway;
use Hyprpay\Payments\Infrastructure\Gateway\Fawry\FawryGateway;
use Hyprpay\Payments\Infrastructure\Http\FakeHttpClient;
use Hyprpay\Payments\Tests\Support\ArrayConfig;
use Hyprpay\Payments\Tests\Support\RecordingActivityRepository;

/**
 * Build a DashboardData over a fixed config and a seeded activity repository.
 *
 * @param  list<PaymentActivityRecord>  $records
 */
function dashboardData(array $records = []): DashboardData
{
    $config = new ArrayConfig(['gateway' => [
        'default' => 'cybersource_uc',
        'gateways' => [
            'cybersource_uc' => ['shared_secret' => 'secret', 'test_mode' => true],
            'fawry' => ['test_mode' => false],
        ],
    ]]);

    $repository = new RecordingActivityRepository;
    foreach ($records as $record) {
        $repository->record($record);
    }

    return new DashboardData($config, $repository);
}

/**
 * Find a gateway health row by its key.
 *
 * @param  list<array{key: string, label: string, configured: bool, testMode: bool, default: bool}>  $gateways
 * @return array{key: string, label: string, configured: bool, testMode: bool, default: bool}
 */
function gatewayRow(array $gateways, string $key): array
{
    return collect($gateways)->firstWhere('key', $key);
}

/**
 * Build an activity record with a status and amount for stats/formatting assertions.
 */
function statusRecord(PaymentStatus $status, ?Money $money = null): PaymentActivityRecord
{
    return PaymentActivityRecord::make('PaymentCharged', GatewayName::CybersourceUnifiedCheckout, $status, $status->isSuccessful(), 'ORD', 'txn', null, $money, '2026-08-24T00:00:00+00:00');
}

it('derives gateway health from configuration', function (): void {
    $gateways = dashboardData()->overview(100)['gateways'];

    expect(gatewayRow($gateways, 'cybersource_uc'))
        ->toMatchArray(['configured' => true, 'testMode' => true, 'default' => true]);

    expect(gatewayRow($gateways, 'fawry'))
        ->toMatchArray(['configured' => false, 'testMode' => false, 'default' => false]);
});

it('lists every gateway even when unconfigured', function (): void {
    expect(dashboardData()->overview(100)['gateways'])->toHaveCount(count(GatewayName::cases()));
});

it('aggregates activity into headline stats', function (): void {
    $stats = dashboardData([
        statusRecord(PaymentStatus::Captured),
        statusRecord(PaymentStatus::Declined),
        statusRecord(PaymentStatus::Captured),
    ])->overview(100)['stats'];

    expect($stats['total'])->toBe(3)
        ->and($stats['successful'])->toBe(2)
        ->and($stats['successRate'])->toBe(67)
        ->and(collect($stats['byStatus'])->firstWhere('label', 'Captured')['count'])->toBe(2)
        ->and(collect($stats['byStatus'])->firstWhere('label', 'Declined')['count'])->toBe(1);
});

it('reports a zero success rate with no activity', function (): void {
    expect(dashboardData()->overview(100)['stats'])->toMatchArray(['total' => 0, 'successRate' => 0]);
});

it('formats recent activity for display', function (): void {
    $recent = dashboardData([statusRecord(PaymentStatus::Captured, Money::minor(2599, 'USD'))])->recentActivity(100);

    expect($recent[0])->toMatchArray([
        'status' => 'Captured',
        'tone' => 'ok',
        'amount' => '25.99 USD',
    ]);
});

it('returns transactions for a gateway that supports listing', function (): void {
    $http = new FakeHttpClient;
    $http->queueJson(['_embedded' => ['transactionSummaries' => [['id' => 't1', 'status' => 'CAPTURED']]]]);

    $gateway = new CybersourceUnifiedCheckoutGateway(testCredentials(), $http);

    $result = dashboardData()->lookup($gateway, 'ORD-7');

    expect($result['supported'])->toBeTrue()
        ->and($result['transactions'])->toHaveCount(1)
        ->and($result['transactions'][0]['transactionId'])->toBe('t1')
        ->and($result['transactions'][0]['status'])->toBe('Captured')
        ->and($result['transactions'][0]['tone'])->toBe('ok');
});

it('degrades gracefully for a gateway that does not support listing', function (): void {
    $gateway = new FawryGateway(testCredentials(), new FakeHttpClient);

    $result = dashboardData()->lookup($gateway, 'ORD-7');

    expect($result['supported'])->toBeFalse()
        ->and($result['transactions'])->toBe([])
        ->and($result['message'])->toContain('does not support');
});
