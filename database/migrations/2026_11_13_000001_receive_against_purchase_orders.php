<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A delivery posted against the order it fills (phase 23).
 *
 * `purchases.purchase_order_id` names the order a delivery was received
 * against, and each `purchase_lines.purchase_order_line_id` the order line it
 * fills. A line with no order line on a delivery that has an order is goods
 * the order did not ask for, and is shown as such.
 *
 * `purchase_order_lines.received_quantity` is what posted deliveries have
 * brought in against the line, in the product's own unit. It is written by
 * the purchase poster with the order locked, and `inventory:verify` checks it
 * against the deliveries behind it.
 *
 * An order partly received and not expected to be filled is closed short:
 * `closed_at`, `closed_by` and `close_reason` say who and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->decimal('received_quantity', 15, 3)->default(0)->after('quantity');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('cancel_reason');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->string('close_reason', 255)->nullable()->after('closed_by');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')
                ->constrained()->restrictOnDelete();
            $table->index('purchase_order_id');
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_line_id')->nullable()->after('purchase_id')
                ->constrained()->restrictOnDelete();
            $table->index('purchase_order_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_line_id');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_at', 'close_reason']);
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn('received_quantity');
        });
    }
};
