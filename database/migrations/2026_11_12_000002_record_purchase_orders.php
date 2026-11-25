<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order placed with a supplier (phase 22).
 *
 * What the shop has asked a supplier to deliver, at the cost agreed, by when.
 * It moves no stock and owes nothing: the stock goes on the shelf when the
 * delivery is posted under Purchases, and phase 23 ties that delivery to the
 * order. Never edited and never deleted — an order placed wrongly is
 * cancelled with a reason and placed again.
 *
 * `purchase_request_id` names the approved request it came from, if any. It
 * was unique, so one request became at most one order — until phase 24.3,
 * which lets an awarded request become one order per winning supplier.
 *
 * `vat_rate` is stamped from the supplier when the order is placed, the way
 * a purchase stamps it, so a printed order reads the same later. Lines are
 * shaped like purchase lines: `quantity` in the product's own unit beside
 * what was typed, `unit_cost` per typed unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_request_id')->nullable()->unique()->constrained()->restrictOnDelete();

            $table->date('order_date')->index();
            $table->date('expected_date')->nullable()->index();
            $table->string('status', 20)->index();

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->text('notes')->nullable();

            $table->uuid('idempotency_key')->nullable()->unique();

            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamps();

            // SQLite does not index a foreign key by itself.
            $table->index('supplier_id');
            $table->index('ordered_by');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('unit_factor', 15, 3)->default(1);
            $table->decimal('unit_quantity', 15, 3);
            $table->decimal('quantity', 15, 3);
            // Agreed with the supplier, per typed unit: per piece, or per box.
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('product_id');
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
