<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Creates the Hyprpay payment store the monitoring dashboard reads from.
 *
 * Modelled on the paylink schema (invoices → payments / payment_attempts), trimmed to what
 * the SDK's PII-safe payment events carry: `invoices` is the order (current rolled-up state),
 * `payments` records each successful payment, `payment_attempts` is the immutable per-operation
 * ledger the dashboard reads, and `webhooks` captures received notifications. Every table is
 * PII-safe — never a card number or a raw gateway payload. All tables share the configured
 * prefix (default "hyprpay_") and run on the configured connection.
 */
return new class extends Migration
{
    public function __construct()
    {
        $connection = config('gateway.dashboard.store.database.connection');
        $this->connection = is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $prefix = $this->prefix();

        $schema->create($prefix.'invoices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('uid', 36)->unique();
            $table->string('gateway', 40)->index();
            $table->string('invoice_number', 191)->nullable();
            $table->string('reference_number', 191)->nullable();
            $table->string('status', 40)->nullable()->index();
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

        $schema->create($prefix.'payments', function (Blueprint $table) use ($prefix): void {
            $table->bigIncrements('id');
            $table->char('uid', 36)->unique();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('gateway', 40)->index();
            $table->string('method_type', 80)->nullable();
            $table->string('transaction_reference', 191)->nullable();
            $table->string('status', 40)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedTinyInteger('scale')->nullable();
            $table->string('paid_at', 40)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['gateway', 'transaction_reference']);
            $table->foreign('invoice_id')->references('id')->on($prefix.'invoices')->nullOnDelete();
        });

        $schema->create($prefix.'payment_attempts', function (Blueprint $table) use ($prefix): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('gateway', 40)->index();
            $table->string('operation', 80);
            $table->string('status', 40)->nullable()->index();
            $table->boolean('success')->nullable();
            $table->string('reason_code', 60)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('order_reference', 191)->nullable()->index();
            $table->string('transaction_id', 191)->nullable();
            $table->string('reference', 191)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedTinyInteger('scale')->nullable();
            $table->string('recorded_at', 40);
            $table->timestamp('created_at')->nullable();
            $table->index(['gateway', 'transaction_id']);
            $table->foreign('invoice_id')->references('id')->on($prefix.'invoices')->nullOnDelete();
        });

        $schema->create($prefix.'webhooks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('gateway', 40)->index();
            $table->string('event_type', 120)->nullable();
            $table->string('transaction_id', 191)->nullable()->index();
            $table->string('status', 40)->nullable();
            $table->boolean('verified')->nullable();
            $table->string('recorded_at', 40);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $prefix = $this->prefix();

        $schema->dropIfExists($prefix.'payment_attempts');
        $schema->dropIfExists($prefix.'payments');
        $schema->dropIfExists($prefix.'webhooks');
        $schema->dropIfExists($prefix.'invoices');
    }

    /**
     * The configured table-name prefix shared by every store table.
     */
    private function prefix(): string
    {
        $prefix = config('gateway.dashboard.store.database.prefix', 'hyprpay_');

        return is_string($prefix) ? $prefix : 'hyprpay_';
    }
};
