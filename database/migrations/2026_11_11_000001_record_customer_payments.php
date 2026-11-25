<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money a customer brings in against what they owe (phase 19).
 *
 * `customer_payments` is the document: who paid, when, how, and how much. It
 * is posted the moment it is saved and never edited, like a sale; a mistake
 * is voided, with a reason, and the invoices owe again what it paid.
 *
 * `customer_payment_allocations` says which invoices it paid, and how much
 * of each. The rows add up to the payment exactly: a payment nobody can
 * place against an invoice would be customer credit, which is stage 6.
 *
 * `reference` is this system's own number (CP-2026-0001). `method_reference`
 * is the GCash or bank number the customer quotes.
 *
 * The index on `sales` serves the overdue figure and the aging report, which
 * read only the sales still owed. Nearly every sale owes nothing, so leading
 * with `balance_due` finds the few that do without reading the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->date('payment_date')->index();
            $table->string('status', 20)->index();

            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('method_reference', 100)->nullable();

            $table->text('notes')->nullable();

            $table->uuid('idempotency_key')->nullable()->unique();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            // A customer's statement reads their payments by date.
            $table->index(['customer_id', 'payment_date'], 'customer_payments_customer_date');
        });

        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            // What this payment put towards that invoice.
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['customer_payment_id', 'sale_id']);
            // What a sale has been paid since: its page, a void, the check.
            $table->index('sale_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['balance_due', 'due_date'], 'sales_open_balance');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_open_balance');
        });

        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');
    }
};
