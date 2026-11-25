<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 31.2: the stock is counted at each location.
 *
 * - `locations.is_default` marks the one location every document posts at
 *   until phases 31.3 and 31.4 let a document name its own. Exactly one is
 *   the default; the first active store is chosen (Main Store), so the till
 *   keeps selling what it sold yesterday.
 * - `location_stocks` holds what each product has at each location. The
 *   total stays on `products.on_hand`, which every screen reads; the ledger
 *   writes both, and `inventory:verify` checks that they agree with the
 *   batches.
 * - Every batch and every movement names its location. Everything already
 *   on the books is put at the default location, so nothing moves: the
 *   totals, the costs and the stock card read exactly as before.
 *
 * The two columns are added empty, filled, and only then made NOT NULL. The
 * indexes are named by hand: MySQL refuses a generated name over 64
 * characters, and SQLite in the tests does not (phase 24 met this once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('type')->index();
        });

        $default = DB::table('locations')->where('is_active', true)->where('type', 'store')->orderBy('id')->value('id')
            ?? DB::table('locations')->where('is_active', true)->orderBy('id')->value('id');

        if ($default === null) {
            $now = now();
            $default = DB::table('locations')->insertGetId([
                'name' => 'Main Store', 'type' => 'store', 'is_default' => true, 'is_active' => true,
                'description' => 'Where goods are sold over the counter.',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        DB::table('locations')->where('id', $default)->update(['is_default' => true]);

        Schema::create('location_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->decimal('on_hand', 15, 3)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'location_id'], 'location_stocks_product_location');
            $table->index(['location_id', 'on_hand'], 'location_stocks_location_on_hand');
        });

        Schema::table('inventory_layers', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('product_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('product_id')
                ->constrained()->restrictOnDelete();
        });

        // Everything on the books is at the default location.
        DB::table('inventory_layers')->update(['location_id' => $default]);
        DB::table('inventory_movements')->update(['location_id' => $default]);

        $now = now();
        DB::table('products')
            ->where('on_hand', '!=', 0)
            ->orderBy('id')
            ->select(['id', 'on_hand'])
            ->chunk(500, function ($products) use ($default, $now) {
                DB::table('location_stocks')->insert($products->map(fn ($p) => [
                    'product_id' => $p->id,
                    'location_id' => $default,
                    'on_hand' => $p->on_hand,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        Schema::table('inventory_layers', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            // The FIFO pick at one location: its batches with stock left,
            // oldest first.
            $table->index(['product_id', 'location_id', 'quantity_remaining', 'id'], 'layers_fifo_at_location');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('inventory_layers', function (Blueprint $table) {
            $table->dropIndex('layers_fifo_at_location');
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::dropIfExists('location_stocks');

        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['is_default']);
            $table->dropColumn('is_default');
        });
    }
};
