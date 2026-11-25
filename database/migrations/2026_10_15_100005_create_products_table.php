<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('sku', 60)->unique();
            $table->string('name', 160)->index();
            $table->text('description')->nullable();

            // A category or unit that is in use cannot be deleted. The policy
            // says so first, with a readable reason; this is the backstop.
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();

            // The last known purchase cost and the price it is sold at. The
            // FIFO valuation is never read from here: it is built from the
            // inventory layers.
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);

            $table->unsignedInteger('reorder_level')->default(0);

            // Stock is never typed in. The posting service moves it, inside
            // the same transaction as the document that caused the move.
            $table->unsignedInteger('on_hand')->default(0)->index();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
