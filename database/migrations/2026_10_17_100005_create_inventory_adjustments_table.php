<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The adjustment document.
     *
     * Phase 7 builds the table and the posting behind it, because opening
     * stock has to arrive through a real document like everything else.
     * Phase 10 puts the screens on top of it.
     */
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();

            // Two documents saved at the same moment must not share a
            // reference, so this is unique and the sequence is locked while
            // a number is taken.
            $table->string('reference', 60)->unique();

            // opening | increase | decrease
            $table->string('kind', 20)->index();
            $table->string('status', 20)->index();

            $table->date('adjustment_date')->index();
            $table->string('reason', 255)->nullable();
            $table->text('notes')->nullable();

            // What the whole document came to, at cost.
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('inventory_adjustment_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_adjustment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // Typed in when stock comes in. Worked out from the layers that
            // were eaten when it goes out, so the figure is never a guess.
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustment_lines');
        Schema::dropIfExists('inventory_adjustments');
    }
};
