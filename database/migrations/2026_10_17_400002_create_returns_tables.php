<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();

            // A return always belongs to the sale it undoes, so what came
            // back is costed at what it actually left at.
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();

            $table->date('return_date')->index();
            $table->string('status', 20)->index();

            $table->string('reason', 255);
            $table->text('notes')->nullable();

            // What is handed back to the customer, and what the goods cost.
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->uuid('idempotency_key')->nullable()->unique();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            // The line of the sale being undone, not merely the product.
            $table->foreignId('sale_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // Prorated: a discounted sale must not refund more than it took.
            $table->decimal('unit_refund', 15, 2)->default(0);
            $table->decimal('line_refund', 15, 2)->default(0);
            $table->decimal('line_cost', 15, 2)->default(0);

            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();

            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();

            $table->date('return_date')->index();
            $table->string('status', 20)->index();

            $table->string('reason', 255);
            $table->text('notes')->nullable();

            $table->decimal('total_cost', 15, 2)->default(0);

            $table->uuid('idempotency_key')->nullable()->unique();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('line_cost', 15, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
    }
};
