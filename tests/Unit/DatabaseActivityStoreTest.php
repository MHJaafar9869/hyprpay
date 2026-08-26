<?php

declare(strict_types=1);

use Hyprpay\Payments\Domain\Enum\GatewayName;
use Hyprpay\Payments\Domain\Enum\PaymentStatus;
use Hyprpay\Payments\Domain\Result\PaymentActivityRecord;
use Hyprpay\Payments\Infrastructure\Dashboard\Actions\RecordActivityToDatabase;
use Hyprpay\Payments\Infrastructure\Dashboard\Queries\RecentActivityFromDatabase;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * Build a database-backed record action + read query over a fresh in-memory SQLite store.
 *
 * @return array{0: RecordActivityToDatabase, 1: RecentActivityFromDatabase, 2: ConnectionInterface}
 */
function databaseActivityStore(): array
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'default');

    $schema = $capsule->getConnection('default')->getSchemaBuilder();

    $schema->create('hyprpay_invoices', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->char('uid', 36);
        $table->string('gateway', 40);
        $table->string('invoice_number', 191)->nullable();
        $table->string('reference_number', 191)->nullable();
        $table->string('status', 40)->nullable();
        $table->string('paid_status', 20)->nullable();
        $table->bigInteger('amount_minor')->nullable();
        $table->string('currency', 3)->nullable();
        $table->unsignedTinyInteger('scale')->nullable();
        $table->boolean('test_mode')->default(true);
        $table->unsignedInteger('attempts_count')->default(0);
        $table->timestamp('last_activity_at')->nullable();
        $table->timestamps();
        $table->unique(['gateway', 'invoice_number']);
    });

    $schema->create('hyprpay_payments', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->char('uid', 36);
        $table->unsignedBigInteger('invoice_id')->nullable();
        $table->string('gateway', 40);
        $table->string('method_type', 80)->nullable();
        $table->string('transaction_reference', 191)->nullable();
        $table->string('status', 40)->nullable();
        $table->bigInteger('amount_minor')->nullable();
        $table->string('currency', 3)->nullable();
        $table->unsignedTinyInteger('scale')->nullable();
        $table->string('paid_at', 40)->nullable();
        $table->timestamp('created_at')->nullable();
    });

    $schema->create('hyprpay_payment_attempts', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('invoice_id')->nullable();
        $table->string('gateway', 40);
        $table->string('operation', 80);
        $table->string('status', 40)->nullable();
        $table->boolean('success')->nullable();
        $table->string('reason_code', 60)->nullable();
        $table->string('message', 500)->nullable();
        $table->string('order_reference', 191)->nullable();
        $table->string('transaction_id', 191)->nullable();
        $table->string('reference', 191)->nullable();
        $table->bigInteger('amount_minor')->nullable();
        $table->string('currency', 3)->nullable();
        $table->unsignedTinyInteger('scale')->nullable();
        $table->string('recorded_at', 40);
        $table->timestamp('created_at')->nullable();
    });

    $schema->create('hyprpay_webhooks', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('gateway', 40);
        $table->string('event_type', 120)->nullable();
        $table->string('transaction_id', 191)->nullable();
        $table->string('status', 40)->nullable();
        $table->boolean('verified')->nullable();
        $table->string('recorded_at', 40);
        $table->timestamp('created_at')->nullable();
    });

    $manager = $capsule->getDatabaseManager();

    return [
        new RecordActivityToDatabase($manager, 'default', 'hyprpay_'),
        new RecentActivityFromDatabase($manager, 'default', 'hyprpay_'),
        $capsule->getConnection('default'),
    ];
}

/**
 * Build an activity record identified by its transaction id, order, status, and operation.
 */
function dbRecord(string $transactionId, ?string $orderReference = 'ORD', PaymentStatus $status = PaymentStatus::Captured, string $operation = 'PaymentCaptured'): PaymentActivityRecord
{
    return PaymentActivityRecord::make($operation, GatewayName::Fawry, $status, $status->isSuccessful(), $orderReference, $transactionId, 'ref', null, '2026-08-24T00:00:00+00:00');
}

it('persists attempts and reads them back newest first', function (): void {
    [$write, $read] = databaseActivityStore();
    $write->record(dbRecord('a'));
    $write->record(dbRecord('b'));

    $recent = $read->recent(10);

    expect($recent)->toHaveCount(2)
        ->and($recent[0]->transactionId)->toBe('b')
        ->and($recent[1]->transactionId)->toBe('a');
});

it('groups attempts under one invoice per order reference and counts them', function (): void {
    [$write, , $connection] = databaseActivityStore();
    $write->record(dbRecord('a', 'ORD-1'));
    $write->record(dbRecord('b', 'ORD-1'));
    $write->record(dbRecord('c', 'ORD-2'));

    $invoices = $connection->table('hyprpay_invoices')->orderBy('invoice_number')->get();

    expect($invoices)->toHaveCount(2)
        ->and((int) $invoices[0]->attempts_count)->toBe(2)
        ->and($invoices[0]->paid_status)->toBe('paid')
        ->and((int) $invoices[1]->attempts_count)->toBe(1);
});

it('records a payment only for successful outcomes', function (): void {
    [$write, , $connection] = databaseActivityStore();
    $write->record(dbRecord('ok-1', 'ORD-1', PaymentStatus::Captured));
    $write->record(dbRecord('ok-2', 'ORD-2', PaymentStatus::Captured));
    $write->record(dbRecord('no-1', 'ORD-3', PaymentStatus::Declined, 'PaymentCharged'));

    expect($connection->table('hyprpay_payments')->count())->toBe(2)
        ->and($connection->table('hyprpay_payment_attempts')->count())->toBe(3)
        ->and($connection->table('hyprpay_invoices')->where('paid_status', 'unpaid')->count())->toBe(1);
});

it('captures webhook events in their own table without an invoice', function (): void {
    [$write, , $connection] = databaseActivityStore();
    $write->record(dbRecord('txn', null, PaymentStatus::Captured, 'WebhookReceived'));

    expect($connection->table('hyprpay_webhooks')->count())->toBe(1)
        ->and($connection->table('hyprpay_invoices')->count())->toBe(0);
});

it('honours the read limit', function (): void {
    [$write, $read] = databaseActivityStore();
    $write->record(dbRecord('a'));
    $write->record(dbRecord('b'));
    $write->record(dbRecord('c'));

    expect($read->recent(2))->toHaveCount(2);
});

it('returns a reference lifecycle oldest first, by order or transaction id', function (): void {
    [$write, $read] = databaseActivityStore();
    $write->record(dbRecord('t1', 'ORD-1', PaymentStatus::Declined, 'PaymentCharged'));
    $write->record(dbRecord('t2', 'ORD-1', PaymentStatus::Captured));
    $write->record(dbRecord('t3', 'ORD-2'));

    $byOrder = $read->lifecycle('ORD-1');
    expect($byOrder)->toHaveCount(2)
        ->and($byOrder[0]->transactionId)->toBe('t1')
        ->and($byOrder[1]->transactionId)->toBe('t2');

    expect($read->lifecycle('t3'))->toHaveCount(1)
        ->and($read->lifecycle('t3')[0]->transactionId)->toBe('t3');
});
