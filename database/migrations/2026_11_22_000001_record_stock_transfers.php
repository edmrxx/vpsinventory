<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 31.5: stock moved from one location to another.
 *
 * A transfer is a posted document, shaped like an adjustment: posted on
 * save, never edited or deleted, voided with a reason while the goods it
 * brought in are untouched. It moves no money and changes no value: each
 * batch it takes from the source opens a new batch at the destination at
 * that batch's own cost.
 *
 * `inventory_layers.transferred_from_layer_id` names the batch a moved one
 * was split from. It cannot be worked out afterwards, so it is written now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();

            // Both filtered on by the list.
            $table->foreignId('from_location_id')->index()->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->index()->constrained('locations')->restrictOnDelete();

            $table->string('status', 20)->index();
            $table->date('transfer_date')->index();
            $table->text('notes')->nullable();

            // What the goods moved were worth, at cost. The same on both sides.
            $table->decimal('total_cost', 15, 2)->default(0);

            // A form sent twice lands on the first transfer.
            $table->uuid('idempotency_key')->nullable()->unique();

            $table->timestamp('posted_at')->nullable();
            // The Staff filter.
            $table->foreignId('posted_by')->nullable()->index()->constrained('users')->nullOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_transfer_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained()->restrictOnDelete();

            // Always in the product's own unit.
            $table->decimal('quantity', 15, 3);

            // Worked out from the batches it came out of.
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->timestamps();
        });

        Schema::table('inventory_layers', function (Blueprint $table) {
            $table->foreignId('transferred_from_layer_id')->nullable()->after('source_id')
                ->constrained('inventory_layers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_layers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transferred_from_layer_id');
        });

        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
