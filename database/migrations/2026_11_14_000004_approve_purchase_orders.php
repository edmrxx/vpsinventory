<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A purchase order is approved before it is sent (phase 24.4, the last of
 * the client's canvass and PO approval request, CR-1).
 *
 * A new order starts *For approval* unless the person placing it may approve
 * orders, and the setting `purchasing.self_approval` lets them approve their
 * own. The approver approves it, or rejects it with a reason. Nothing can be
 * received against an order until it is approved.
 *
 * Every order already on the books counts as approved, so nothing in
 * progress gets stuck: `approved_at` is the moment it was placed, and
 * `approved_by` is whoever approved the request it came from — the name the
 * order already printed on its "Approved by" line — so an old order reprints
 * exactly as it did. An order placed directly before this phase printed a
 * blank line there, and still does.
 *
 * The statuses are a plain string column, so the two new ones — for
 * approval and rejected — need no change to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('ordered_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->string('reject_reason', 255)->nullable()->after('rejected_by');
        });

        DB::table('purchase_orders')->update([
            'approved_at' => DB::raw('created_at'),
            'approved_by' => DB::raw('(SELECT purchase_requests.decided_by FROM purchase_requests WHERE purchase_requests.id = purchase_orders.purchase_request_id)'),
        ]);
    }

    public function down(): void
    {
        // An order still waiting goes back to open, as every order was before
        // this phase; a rejected one reads as cancelled, with its reason.
        DB::table('purchase_orders')->where('status', 'pending_approval')->update(['status' => 'open']);
        DB::table('purchase_orders')->where('status', 'rejected')->update([
            'status' => 'cancelled',
            'cancelled_at' => DB::raw('rejected_at'),
            'cancelled_by' => DB::raw('rejected_by'),
            'cancel_reason' => DB::raw('reject_reason'),
        ]);

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'rejected_at', 'reject_reason']);
        });
    }
};
