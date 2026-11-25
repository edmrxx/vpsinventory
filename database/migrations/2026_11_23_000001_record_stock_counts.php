<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 32: the count sheet.
 *
 * A count is not a posted document. Starting one takes what the books say is
 * at one location — the cutoff — and the counters write what they find
 * beside it. Nothing moves until an approver approves it; then the
 * differences post as up to two ordinary adjustments (reason *count*), one
 * for what was found over and one for what was short, at that location.
 *
 * `system_quantity` is the figure at the cutoff. A sale after it is already
 * on the books, so only the difference — counted less that figure — is ever
 * posted, never the counted figure itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();

            // Filtered on by the list. One open count per location.
            $table->foreignId('location_id')->index()->constrained('locations')->restrictOnDelete();

            // Counting one category only; null counts everything there.
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('status', 20)->index();

            // The day it was started, for the list; the moment, for the sheet.
            $table->date('count_date')->index();
            $table->timestamp('cutoff_at');

            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();

            // The Staff filter.
            $table->foreignId('started_by')->nullable()->index()->constrained('users')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            // The last time an approver sent it back for a recount, and why.
            $table->timestamp('sent_back_at')->nullable();
            $table->foreignId('sent_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('send_back_reason', 255)->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            // What approving it posted. Null when nothing was over or short.
            $table->foreignId('gain_adjustment_id')->nullable()->constrained('inventory_adjustments')->nullOnDelete();
            $table->foreignId('loss_adjustment_id')->nullable()->constrained('inventory_adjustments')->nullOnDelete();

            // At cost, written when it is approved.
            $table->decimal('gain_value', 15, 2)->default(0);
            $table->decimal('loss_value', 15, 2)->default(0);

            $table->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_count_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained()->restrictOnDelete();

            // On the books at the location at the cutoff, in the product's own unit.
            $table->decimal('system_quantity', 15, 3)->default(0);

            // What was found. Null means not counted yet — never zero.
            $table->decimal('counted_quantity', 15, 3)->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();

            // Written when the count is approved: counted less the cutoff
            // figure, and what that came to at cost.
            $table->decimal('variance', 15, 3)->nullable();
            $table->decimal('variance_value', 15, 2)->nullable();

            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
