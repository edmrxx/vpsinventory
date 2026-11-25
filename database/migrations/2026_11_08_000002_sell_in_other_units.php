<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product may be bought and sold in other units: a box of 100, a roll of
 * 75 m. The stock stays counted in the product's own unit.
 *
 * `product_units` holds what one of each other unit is worth in the
 * product's own unit (`factor`), and what it sells for when that is not
 * simply the piece price times the factor.
 *
 * A sale or purchase line keeps `quantity` in the product's own unit —
 * that is the figure the ledger, the returns and the reports read — and now
 * also records what was typed: `unit_quantity` of `unit_id`, at
 * `unit_factor` each. A null `unit_id` means the product's own unit. The
 * factor is copied onto the line, so changing a box from 100 to 50 later
 * never changes what an old document says.
 *
 * On a purchase line `unit_price` is what one of the typed unit cost, and
 * `unit_cost` stays the cost of one piece, which is what the batch takes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            // How many of the product's own unit one of these holds.
            $table->decimal('factor', 15, 3);
            // Null: the piece price times the factor.
            $table->decimal('selling_price', 15, 2)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('product_id')
                ->constrained()->restrictOnDelete();
            $table->decimal('unit_factor', 15, 3)->default(1)->after('unit_id');
            $table->decimal('unit_quantity', 15, 3)->default(0)->after('unit_factor');
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('product_id')
                ->constrained()->restrictOnDelete();
            $table->decimal('unit_factor', 15, 3)->default(1)->after('unit_id');
            $table->decimal('unit_quantity', 15, 3)->default(0)->after('unit_factor');
            $table->decimal('unit_price', 15, 2)->default(0)->after('unit_quantity');
        });

        // Every line already on the books was typed in the product's own
        // unit, so what was typed is what moved.
        DB::table('sale_lines')->update(['unit_quantity' => DB::raw('quantity')]);
        DB::table('purchase_lines')->update([
            'unit_quantity' => DB::raw('quantity'),
            'unit_price' => DB::raw('unit_cost'),
        ]);
    }

    /**
     * Refused once a line was typed in another unit: putting the columns
     * back would lose what that document says it sold.
     */
    public function down(): void
    {
        foreach (['sale_lines', 'purchase_lines'] as $table) {
            if (DB::table($table)->whereNotNull('unit_id')->exists()) {
                throw new RuntimeException(
                    "{$table} holds lines typed in another unit. Rolling back would lose them."
                );
            }
        }

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['unit_factor', 'unit_quantity', 'unit_price']);
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['unit_factor', 'unit_quantity']);
        });

        Schema::dropIfExists('product_units');
    }
};
