<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_layer_consumptions', function (Blueprint $table) {
            $table->id();

            // Which outgoing movement ate which batch, and how much of it.
            // Without this a sales return could not put the stock back at the
            // cost it left at, and a void could not be undone.
            $table->foreignId('inventory_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_layer_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 15, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_layer_consumptions');
    }
};
