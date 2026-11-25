<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a sale was paid: cash, GCash, a bank transfer, or several at once.
 *
 * `payment_methods` is the list the owner keeps on its own screen. Four are
 * put here rather than in a seeder, so a server that is deployed without
 * `db:seed` still has them — a till with no Cash cannot sell.
 *
 * `sale_payments` holds one row for each way a sale was paid. `amount` is
 * what that payment put towards the sale, so the rows of a sale always add
 * up to its total exactly. `tendered` is what the customer handed over, and
 * is kept on the cash row only: ₱2,000 tendered against ₱1,850 owed is
 * ₱150 change. `reference` is the GCash or bank transaction number.
 *
 * Every sale already on the books was counted as paid in cash on its date,
 * because there was nothing else to count. That is now written down: one
 * Cash row for its total, so no figure moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            // cash, ewallet, bank, card or other. Only cash gives change.
            $table->string('kind', 20)->index();
            // A GCash or bank payment carries a transaction number.
            $table->boolean('requires_reference')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            // What this payment put towards the sale.
            $table->decimal('amount', 15, 2);
            // What was handed over, on a cash row. The change is the gap.
            $table->decimal('tendered', 15, 2)->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('payment_methods')->insert([
            ['name' => 'Cash', 'kind' => 'cash', 'requires_reference' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'GCash', 'kind' => 'ewallet', 'requires_reference' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Maya', 'kind' => 'ewallet', 'requires_reference' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Bank transfer', 'kind' => 'bank', 'requires_reference' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $cash = DB::table('payment_methods')->where('kind', 'cash')->value('id');

        // Every sale so far, as paid in cash for exactly its total. A sale
        // that came to nothing — a full discount — took no money.
        DB::table('sale_payments')->insertUsing(
            ['sale_id', 'payment_method_id', 'amount', 'tendered', 'reference', 'created_at', 'updated_at'],
            DB::table('sales')
                ->where('total_amount', '>', 0)
                ->selectRaw('id, ?, total_amount, NULL, NULL, created_at, updated_at', [$cash]),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('payment_methods');
    }
};
