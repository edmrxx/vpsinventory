<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quantities may now carry up to three decimals: 2.5 m, 0.125 kg.
 *
 * Every quantity column goes from a whole number to DECIMAL(15,3). What is
 * already there is untouched: 10 becomes 10.000, which every screen still
 * shows as 10.
 *
 * A unit decides whether its products may use the decimals, through
 * `allows_decimals`. It starts off for every unit already on the books —
 * pieces stay pieces — and the owner turns it on for the ones sold by
 * measure. It is not switched on here by unit code: after `data:reset` the
 * units are the client's own, and their codes are theirs to choose.
 *
 * A `change()` keeps only what it restates, so every default is written
 * again below. Leave one out and an insert that relies on it fails.
 */
return new class extends Migration
{
    /** Table => [column => default, or null for none]. */
    protected const COLUMNS = [
        'products' => ['reorder_level' => 0, 'on_hand' => 0],
        'inventory_layers' => ['quantity_received' => null, 'quantity_remaining' => null],
        'inventory_movements' => ['quantity_in' => 0, 'quantity_out' => 0, 'balance_after' => null],
        'inventory_layer_consumptions' => ['quantity' => null, 'returned_quantity' => 0],
        'inventory_adjustment_lines' => ['quantity' => null],
        'purchase_lines' => ['quantity' => null, 'returned_quantity' => 0],
        'sale_lines' => ['quantity' => null, 'returned_quantity' => 0],
        'purchase_return_lines' => ['quantity' => null],
        'sales_return_lines' => ['quantity' => null],
    ];

    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // Filtered on the list, never sorted, so it carries no index.
            $table->boolean('allows_decimals')->default(false)->after('name');
        });

        foreach (self::COLUMNS as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column => $default) {
                    $definition = $table->decimal($column, 15, 3);

                    if ($default !== null) {
                        $definition->default($default);
                    }

                    $definition->change();
                }
            });
        }
    }

    public function down(): void
    {
        // Going back to whole numbers would cut 2.5 to 2 without a word and
        // leave the stock disagreeing with its batches. Refuse instead.
        foreach (self::COLUMNS as $name => $columns) {
            foreach (array_keys($columns) as $column) {
                $fractional = DB::table($name)
                    ->whereRaw("ROUND({$column}, 0) <> {$column}")
                    ->exists();

                if ($fractional) {
                    throw new RuntimeException(
                        "{$name}.{$column} holds a quantity with decimals. Correct it before rolling this back."
                    );
                }
            }
        }

        foreach (self::COLUMNS as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column => $default) {
                    $definition = $table->unsignedInteger($column);

                    if ($default !== null) {
                        $definition->default($default);
                    }

                    $definition->change();
                }
            });
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('allows_decimals');
        });
    }
};
