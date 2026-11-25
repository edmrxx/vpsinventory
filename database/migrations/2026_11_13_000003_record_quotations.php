<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price offered to a customer, valid until a date (phase 28).
 *
 * A quotation moves no stock and owes nothing, and reserves nothing either:
 * reserving is the sales order, phase 29. It is open until it is accepted —
 * turned into a sale, `sale_id` naming it, unique so one quotation becomes
 * one sale — or cancelled with a reason. Past `valid_until` an open one reads
 * as expired; nothing is written for that, so the date alone decides.
 *
 * Its lines are shaped like sale lines, so the sale can copy them:
 * `quantity` in the product's own unit, and beside it what was typed —
 * `unit_quantity` of `unit_id` at `unit_factor` each, at `unit_price` per
 * typed unit. The discount is per document, as on a sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Who the quotation is for, by name — a walk-in prospect, or the
            // person at a customer's company it is addressed to.
            $table->string('attention', 160)->nullable();

            $table->date('quote_date')->index();
            $table->date('valid_until')->index();
            $table->string('status', 20)->index();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();

            $table->uuid('idempotency_key')->nullable()->unique();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();

            // Accepted: the sale it became.
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamps();

            // SQLite does not index a foreign key by itself.
            $table->index('customer_id');
            $table->index('prepared_by');
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('unit_factor', 15, 3)->default(1);
            $table->decimal('unit_quantity', 15, 3);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->index('quotation_id');
            $table->index('product_id');
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
