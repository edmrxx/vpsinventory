<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 31.3: a delivery is received at a location, and an order says
 * where it is to be delivered.
 *
 * - `purchases.location_id` — where the goods went on the shelf. Every
 *   delivery already on the books is set to the location its batches sit at,
 *   which since phase 31.2 is the default; then the column is made NOT NULL.
 * - `purchase_orders.deliver_to_location_id` — optional. An order from
 *   before this phase names none and reprints exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('supplier_id')
                ->constrained()->restrictOnDelete();
        });

        // Where its batches actually sit; the default for one that left none.
        $default = DB::table('locations')->where('is_default', true)->orderBy('id')->value('id');

        DB::table('purchases')->orderBy('id')->select('id')->chunk(500, function ($purchases) use ($default) {
            foreach ($purchases as $purchase) {
                $at = DB::table('inventory_layers')
                    ->where('source_type', 'App\\Models\\Purchase')
                    ->where('source_id', $purchase->id)
                    ->value('location_id');

                DB::table('purchases')->where('id', $purchase->id)->update(['location_id' => $at ?? $default]);
            }
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('deliver_to_location_id')->nullable()->after('supplier_id')
                ->constrained('locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deliver_to_location_id');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
