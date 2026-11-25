<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale may be charged to a customer's account.
 *
 * `customers.credit_limit` is how much a customer may owe at once. Zero means
 * no credit at all, never "no limit", so every customer already on the books
 * starts with none. `payment_terms_days` is how long they have to pay.
 *
 * `sales.on_account` is the part of a sale not paid at the counter, and stays
 * as it was posted. `sales.balance_due` is what is still owed of it: a return
 * takes it down, a void clears it, and phase 19's collections will take it
 * down too. A customer's balance is the sum of these, never a figure of its
 * own, so the two cannot disagree. `sales.due_date` is stamped when the sale
 * posts, so changing a customer's terms never moves an old invoice.
 *
 * The index serves that sum. Without `balance_due` in it, adding up what the
 * walk-in customer owes would read every counter sale ever made to find none.
 *
 * `sales_returns.credited_amount` is how much of a return came off what the
 * customer still owed. The rest of its value was handed back as cash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('on_account', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->index(['customer_id', 'balance_due'], 'sales_customer_balance');
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->decimal('credited_amount', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn('credited_amount');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_customer_balance');
            $table->dropColumn(['on_account', 'balance_due', 'due_date']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'payment_terms_days']);
        });
    }
};
