<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders from the award (phase 24.3, the client's request CR-1).
 *
 * An awarded request becomes one purchase order for each supplier that won
 * an item, so the phase 22 rule "one request, one order" is lifted: the
 * unique index on `purchase_orders.purchase_request_id` becomes a plain one.
 * `PurchaseOrderDesk` still refuses a second order by hand, with the request
 * row locked — the guard moves from the index to the lock.
 *
 * `purchase_order_lines.purchase_request_line_id` says which request line an
 * order line came from, so the request knows which of its items were
 * ordered and where. A line ordered by hand, or placed directly, has none.
 *
 * The plain index is added before the unique one is dropped: MySQL needs an
 * index under the foreign key at every moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->index('purchase_request_id', 'purchase_orders_request');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('purchase_orders_purchase_request_id_unique');
        });

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->foreignId('purchase_request_line_id')->nullable()->after('purchase_order_id')
                ->constrained()->nullOnDelete();
            $table->index('purchase_request_line_id', 'purchase_order_lines_request_line');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropForeign(['purchase_request_line_id']);
            $table->dropIndex('purchase_order_lines_request_line');
            $table->dropColumn('purchase_request_line_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->unique('purchase_request_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('purchase_orders_request');
        });
    }
};
