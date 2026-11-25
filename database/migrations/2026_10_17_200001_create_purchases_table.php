<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            // Ours, taken from the locked sequence when the document posts.
            $table->string('reference', 60)->unique();

            // Theirs: the number printed on the supplier's own invoice. A
            // supplier cannot send the same invoice number twice, so the
            // pair is unique and the same delivery cannot be booked twice.
            $table->string('supplier_reference', 60)->nullable();

            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->date('purchase_date')->index();
            $table->string('status', 20)->index();

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();

            // Sent with the form and kept, so a double-clicked Save lands on
            // the purchase it already made instead of making a second one.
            $table->uuid('idempotency_key')->nullable()->unique();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_reference'], 'purchases_supplier_invoice');
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_total', 15, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
    }
};
