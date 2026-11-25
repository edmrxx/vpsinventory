<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order a customer has placed, holding stock for them (phase 29).
 *
 * Pending reserves nothing. Approved reserves its lines until
 * `reserve_until`; past that date it reads as expired and reserves nothing,
 * worked out from the date alone. It ends when it becomes a sale (`sale_id`,
 * unique) or is cancelled, which releases the stock. No stock moves until
 * the sale posts.
 *
 * What is reserved is never stored on the product: it is always the sum of
 * the lines of approved orders still within their date, so it cannot drift.
 * The index on (status, reserve_until) finds those orders.
 *
 * `quotation_id` names the quotation it came from, unique, so one quotation
 * becomes one order or one sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('attention', 160)->nullable();

            $table->date('order_date')->index();
            $table->date('reserve_until');
            $table->string('status', 20)->index();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();

            $table->uuid('idempotency_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('quotation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('fulfilled_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamps();

            $table->index('customer_id');
            $table->index('created_by');
            $table->index('reserve_until');
            $table->index(['status', 'reserve_until'], 'sales_orders_reserving');
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('unit_factor', 15, 3)->default(1);
            $table->decimal('unit_quantity', 15, 3);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->index('sales_order_id');
            $table->index('product_id');
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
