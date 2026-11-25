<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending payment: the kiosk takes the order, the cashier takes the money
 * (CR-2, the client's meeting of 24 November 2026).
 *
 * The tablet on the shop floor sends the customer's order to the cashier
 * instead of taking the money itself. It holds its goods at its location
 * from the moment it is sent until `hold_until` — the day it was sent, by
 * default — so nobody sells them meanwhile. Past that date it reads as
 * expired and holds nothing, worked out from the date alone: nothing runs at
 * closing. It ends when the cashier is paid (`sale_id`, unique: one order,
 * one sale) or is cancelled with a reason, which frees the goods.
 *
 * Nothing moves on the shelf here. The stock leaves when the sale posts.
 * What is held is never stored on the product: `Reservations` adds up these
 * lines, as it does a sales order's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_payments', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->date('order_date')->index();
            $table->date('hold_until');
            $table->string('status', 20)->index();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('note', 255)->nullable();

            $table->uuid('idempotency_key')->nullable()->unique();
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamps();

            $table->index('location_id');
            $table->index('customer_id');
            $table->index('taken_by');
            $table->index('hold_until');
            $table->index(['status', 'hold_until'], 'pending_payments_holding');
        });

        Schema::create('pending_payment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pending_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('unit_factor', 15, 3)->default(1);
            $table->decimal('unit_quantity', 15, 3);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->index('pending_payment_id');
            $table->index('product_id');
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_payment_lines');
        Schema::dropIfExists('pending_payments');
    }
};
