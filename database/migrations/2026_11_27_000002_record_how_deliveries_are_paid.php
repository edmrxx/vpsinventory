<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24.5 (CR-5): how a delivery was paid on delivery.
 *
 * What was paid on delivery is always `total_amount − on_account`, so it
 * needs no column of its own; since this phase it may be part of the bill
 * (a down payment), the rest going on the supplier's account. These two say
 * how that part was paid — Cash, GCash, a cheque — and its reference. Null
 * on every delivery from before: paid, but how was never written down.
 *
 * A **Cheque** method is put in beside the four from phase 16 (one marked
 * "Needs a reference number": the cheque number), unless the shop already
 * made one. Like them it can be renamed or switched off on Payment Methods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('due_date')
                ->constrained()->restrictOnDelete();
            $table->string('payment_reference', 60)->nullable()->after('payment_method_id');
        });

        $exists = DB::table('payment_methods')
            ->whereIn(DB::raw('LOWER(name)'), ['cheque', 'check'])
            ->exists();

        if (! $exists) {
            DB::table('payment_methods')->insert([
                'name' => 'Cheque',
                'kind' => 'other',
                'requires_reference' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn('payment_reference');
        });
    }
};
