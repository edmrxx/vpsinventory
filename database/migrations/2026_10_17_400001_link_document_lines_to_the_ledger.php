<?php

use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Sale;
use App\Models\SaleLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A return has to find the exact batches its own line touched.
     *
     * Until now a line only knew its totals. The ledger kept the detail, but
     * nothing tied the two together, so a return could not say which units
     * were coming back. These columns are that tie.
     */
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            // Which movement took this line's stock out, and so which
            // batches it ate.
            $table->foreignId('inventory_movement_id')
                ->nullable()
                ->after('product_id')
                ->constrained()
                ->nullOnDelete();

            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            // Which batch this line opened. A purchase return draws from
            // that batch alone, never from the oldest in the warehouse.
            $table->foreignId('inventory_layer_id')
                ->nullable()
                ->after('product_id')
                ->constrained()
                ->nullOnDelete();

            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
        });

        Schema::table('inventory_layer_consumptions', function (Blueprint $table) {
            // How much of what this movement took has since come back.
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
        });

        Schema::table('inventory_adjustments', function (Blueprint $table) {
            // A double-clicked Save would otherwise post twice.
            $table->uuid('idempotency_key')->nullable()->unique()->after('notes');
        });

        $this->backfill();
    }

    /**
     * Fill the link in for documents posted before this migration.
     *
     * Lines and their ledger rows were written in the same order inside one
     * transaction, so pairing them in id order is exact. Only rows a
     * purchase actually created are matched: a voided purchase's own
     * movements must not be mistaken for them.
     */
    protected function backfill(): void
    {
        SaleLine::with('sale')->orderBy('id')->get()
            ->groupBy('sale_id')
            ->each(function ($lines) {
                $movements = InventoryMovement::where('source_type', (new Sale)->getMorphClass())
                    ->where('source_id', $lines->first()->sale_id)
                    ->where('quantity_out', '>', 0)
                    ->orderBy('id')
                    ->pluck('id');

                $lines->values()->each(function (SaleLine $line, int $index) use ($movements) {
                    if (isset($movements[$index])) {
                        $line->newQuery()->whereKey($line->id)
                            ->update(['inventory_movement_id' => $movements[$index]]);
                    }
                });
            });

        PurchaseLine::orderBy('id')->get()
            ->groupBy('purchase_id')
            ->each(function ($lines) {
                $layers = InventoryLayer::where('source_type', (new Purchase)->getMorphClass())
                    ->where('source_id', $lines->first()->purchase_id)
                    ->orderBy('id')
                    ->pluck('id');

                $lines->values()->each(function (PurchaseLine $line, int $index) use ($layers) {
                    if (isset($layers[$index])) {
                        $line->newQuery()->whereKey($line->id)
                            ->update(['inventory_layer_id' => $layers[$index]]);
                    }
                });
            });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_movement_id');
            $table->dropColumn('returned_quantity');
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_layer_id');
            $table->dropColumn('returned_quantity');
        });

        Schema::table('inventory_layer_consumptions', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });

        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
