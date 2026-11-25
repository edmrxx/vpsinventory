<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 33: a loss is reported, held aside, and written off only once
 * approved.
 *
 * A loss report is not a posted document. While it waits, the goods it
 * names are **held** at their own location: still on the books, but no sale,
 * transfer or customer order may take them (`App\Support\Reservations`).
 * Approving it posts an ordinary decrease adjustment with the report's
 * reason; rejecting or withdrawing it frees the goods again.
 *
 * The photos are evidence, so they sit on the private disk and are served
 * only to those who may read the report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loss_reports', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 60)->unique();

            // Where the goods are held; filtered on by the list.
            $table->foreignId('location_id')->index()->constrained('locations')->restrictOnDelete();

            // One of the loss reasons in StoreAdjustmentRequest::REASONS.
            $table->string('reason', 30)->index();

            $table->string('status', 20)->index();
            $table->date('report_date')->index();
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();

            // The Staff filter.
            $table->foreignId('reported_by')->nullable()->index()->constrained('users')->nullOnDelete();

            // The approver's answer: approved (written off) or rejected.
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 255)->nullable();

            // Withdrawn by the person who reported it, or by an approver.
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            // What approving it posted, and what that came to at cost.
            $table->foreignId('adjustment_id')->nullable()->constrained('inventory_adjustments')->nullOnDelete();
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->timestamps();
        });

        Schema::create('loss_report_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loss_report_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained()->restrictOnDelete();

            // Always in the product's own unit.
            $table->decimal('quantity', 15, 3);

            // Written when approved, from the batches the write-off ate.
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->timestamps();
        });

        Schema::create('loss_report_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loss_report_id')->index()->constrained()->cascadeOnDelete();

            // On the private disk, under losses/. Never a name from a request.
            $table->string('path');
            $table->string('original_name')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loss_report_photos');
        Schema::dropIfExists('loss_report_lines');
        Schema::dropIfExists('loss_reports');
    }
};
