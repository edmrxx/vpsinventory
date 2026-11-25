<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // The document behind the move.
            $table->nullableMorphs('source');

            $table->string('reference', 60)->index();
            $table->string('type', 30)->index();
            $table->date('movement_date')->index();

            $table->unsignedInteger('quantity_in')->default(0);
            $table->unsignedInteger('quantity_out')->default(0);

            // What the product held once this row was written. Kept so the
            // stock card reads straight off the table and never has to add
            // up every row before it.
            $table->unsignedInteger('balance_after');

            // For an incoming row this is what the batch cost. For an
            // outgoing one it is the weighted cost of the layers it ate,
            // which is the cost of goods sold for that line.
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->timestamps();

            // The stock card: this product's rows in the order they happened.
            $table->index(['product_id', 'id'], 'movements_stock_card');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
