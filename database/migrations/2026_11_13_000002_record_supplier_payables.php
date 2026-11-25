<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop owes its suppliers (phase 24).
 *
 * The delivery is the bill: the supplier's invoice number is already on it.
 * `purchases.on_account` is what was not paid on delivery, as posted;
 * `balance_due` is what is still owed; `due_date` is stamped at posting from
 * the supplier's `payment_terms_days`. A supplier's balance is always the sum
 * of `balance_due` — no figure on the supplier. Every delivery posted before
 * this phase counts as paid on delivery, which is what the system assumed.
 *
 * A purchase return comes off an unpaid bill first (`credited_amount`); what
 * is left of it, on a bill already paid, is kept as credit with that supplier
 * (`supplier_credit_amount`), used against a later bill. A return posted
 * before this phase carries neither, and still reads as refunded in cash.
 *
 * `supplier_payments` is money paid to a supplier against named bills, like
 * a customer payment the other way round. `credit_used` is supplier credit
 * put towards the same bills; the allocations add up to `amount` plus
 * `credit_used` exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('is_vat_registered');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('on_account', 15, 2)->default(0)->after('total_amount');
            $table->decimal('balance_due', 15, 2)->default(0)->after('on_account');
            $table->date('due_date')->nullable()->after('balance_due');

            // Payables read only the bills still owed. Nearly every delivery
            // owes nothing, so leading with the balance finds the few.
            $table->index(['balance_due', 'due_date'], 'purchases_open_balance');
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->decimal('credited_amount', 15, 2)->default(0)->after('total_cost');
            $table->decimal('supplier_credit_amount', 15, 2)->default(0)->after('credited_amount');
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->date('payment_date')->index();
            $table->string('status', 20)->index();

            // Null when the payment is supplier credit alone.
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('credit_used', 15, 2)->default(0);
            $table->string('method_reference', 100)->nullable();

            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'payment_date'], 'supplier_payments_supplier_date');
            $table->index('payment_method_id');
            $table->index('posted_by');
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            // Named by hand: the generated names pass MySQL's 64-character limit.
            $table->unique(['supplier_payment_id', 'purchase_id'], 'supplier_allocations_payment_purchase');
            $table->index('purchase_id', 'supplier_allocations_purchase');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn(['credited_amount', 'supplier_credit_amount']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_open_balance');
            $table->dropColumn(['on_account', 'balance_due', 'due_date']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('payment_terms_days');
        });
    }
};
