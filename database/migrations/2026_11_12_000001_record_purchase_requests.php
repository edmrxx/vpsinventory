<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asking to buy, before anything is bought (phase 21).
 *
 * A purchase request says what is needed, how much, roughly what it will
 * cost and why. It moves no stock and owes nobody anything: it waits for
 * somebody allowed to approve it, who approves or rejects it. A request
 * nobody needs any more is closed, with a reason. Phase 22 turns an approved
 * request into a purchase order and closes it then.
 *
 * Its lines are shaped like a purchase line, so the order can copy them:
 * `quantity` in the product's own unit, and beside it what was typed —
 * `unit_quantity` of `unit_id` at `unit_factor` each, at an estimated
 * `unit_cost` per typed unit.
 *
 * The preferred supplier is a suggestion, so deleting that supplier only
 * clears it. A product or unit named on a line cannot be deleted; the
 * policies say so before the database has to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();
            $table->date('request_date')->index();
            $table->date('needed_by')->nullable();
            $table->string('status', 20)->index();

            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();

            $table->text('reason');
            $table->decimal('estimated_total', 15, 2)->default(0);
            $table->text('notes')->nullable();

            $table->uuid('idempotency_key')->nullable()->unique();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // Approved or rejected: who, when and what they said.
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 255)->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_reason', 255)->nullable();

            $table->timestamps();

            // SQLite does not index a foreign key by itself. The list
            // filters on both, and the dashboard reads the pending ones by
            // whoever raised them.
            $table->index('supplier_id');
            $table->index(['status', 'requested_by'], 'purchase_requests_status_requester');
        });

        Schema::create('purchase_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('unit_factor', 15, 3)->default(1);
            $table->decimal('unit_quantity', 15, 3);
            $table->decimal('quantity', 15, 3);
            // Estimated, per typed unit: per piece, or per box.
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->index('purchase_request_id');
            $table->index('product_id');
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
    }
};
