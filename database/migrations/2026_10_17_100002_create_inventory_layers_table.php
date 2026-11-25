<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_layers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // The document that brought this batch in.
            $table->nullableMorphs('source');

            $table->string('reference', 60)->index();
            $table->date('layer_date');

            $table->unsignedInteger('quantity_received');
            // What is left of this batch. FIFO eats the oldest row still
            // holding something, so this is the column the picker reads.
            $table->unsignedInteger('quantity_remaining');

            $table->decimal('unit_cost', 15, 2);

            $table->timestamps();

            // The FIFO pick: this product's layers, oldest first, that still
            // hold stock.
            $table->index(['product_id', 'quantity_remaining', 'id'], 'layers_fifo_pick');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_layers');
    }
};
