<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();

            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->date('sale_date')->index();
            $table->string('status', 20)->index();

            // The discount is taken off the whole document, not the line, so
            // it sits here. Subtotal is the lines added up; total is what
            // was actually charged.
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            // What the goods cost the business, taken from the batches the
            // sale ate. Gross profit is total minus this, and it is the
            // figure the dashboard is built on.
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->text('notes')->nullable();

            $table->uuid('idempotency_key')->nullable()->unique();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);

            // Filled in by the ledger once it knows which batches the line
            // ate, so it is never a guess.
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('line_cost', 15, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
    }
};
