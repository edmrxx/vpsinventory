<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 31.4: a sale, an adjustment and a sales order each name their
 * location.
 *
 * - `sales.location_id` — where the goods left the shelf.
 * - `inventory_adjustments.location_id` — where the stock was counted in or
 *   out (opening stock too).
 * - `sales_orders.location_id` — where the order holds its stock.
 *
 * A sale or adjustment already on the books is set to where its first
 * movement happened — the posting, never a void's reversal, which comes
 * after it — and since phase 31.2 that is the default. A voided one keeps
 * its place too. A sales order moved no stock, so it gets the default,
 * where every reservation was weighed until now. Then each column is made
 * NOT NULL.
 *
 * Returns take no column: a sales return goes back into the batches its
 * sale ate, a purchase return leaves the batch its delivery made, so each
 * is wherever its document is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $default = DB::table('locations')->where('is_default', true)->orderBy('id')->value('id');

        foreach (['sales' => 'App\\Models\\Sale', 'inventory_adjustments' => 'App\\Models\\InventoryAdjustment'] as $table => $morph) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('location_id')->nullable()->after('reference')
                    ->constrained()->restrictOnDelete();
            });

            DB::table($table)->orderBy('id')->select('id')->chunk(500, function ($rows) use ($table, $morph, $default) {
                foreach ($rows as $row) {
                    $at = DB::table('inventory_movements')
                        ->where('source_type', $morph)
                        ->where('source_id', $row->id)
                        ->orderBy('id')
                        ->value('location_id');

                    DB::table($table)->where('id', $row->id)->update(['location_id' => $at ?? $default]);
                }
            });

            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('location_id')->nullable(false)->change();
            });
        }

        Schema::table('sales_orders', function (Blueprint $t) {
            $t->foreignId('location_id')->nullable()->after('reference')
                ->constrained()->restrictOnDelete();
        });

        DB::table('sales_orders')->update(['location_id' => $default]);

        Schema::table('sales_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('location_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        foreach (['sales_orders', 'inventory_adjustments', 'sales'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('location_id');
            });
        }
    }
};
