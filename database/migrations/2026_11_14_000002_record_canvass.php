<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The canvass (phase 24.2, the client's request CR-1): the prices suppliers
 * quote against an approved purchase request, and the supplier chosen for
 * each item.
 *
 * - `supplier_quotes` — one per supplier per request, with what came with
 *   the price: delivery days, payment terms, a note.
 * - `supplier_quote_lines` — that supplier's price for one request line, per
 *   the unit the line was typed in. An item the supplier does not carry has
 *   no row.
 * - `purchase_request_lines.awarded_quote_line_id` — the price that won, and
 *   `award_reason` when it was not the lowest. `purchase_requests.awarded_at`
 *   and `awarded_by` say when and by whom.
 *
 * The award points back at a quote line while quote lines point at request
 * lines, so the award is cleared rather than refused when a quote line is
 * deleted: `data:reset` empties the quotes before the requests. Nothing in
 * the app deletes a quote once it has won — the desk refuses it.
 *
 * Index names are given by hand: MySQL refuses a generated one over 64
 * characters (phase 24 met that).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->string('payment_terms', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One quote per supplier per request.
            $table->unique(['purchase_request_id', 'supplier_id'], 'supplier_quotes_request_supplier');
            $table->index('supplier_id', 'supplier_quotes_supplier');
        });

        Schema::create('supplier_quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_line_id')->constrained()->restrictOnDelete();
            // Per the unit the request line was typed in, VAT included.
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->unique(['supplier_quote_id', 'purchase_request_line_id'], 'quote_lines_quote_request_line');
            $table->index('purchase_request_line_id', 'quote_lines_request_line');
        });

        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->foreignId('awarded_quote_line_id')->nullable()->after('line_total')
                ->constrained('supplier_quote_lines')->nullOnDelete();
            $table->string('award_reason', 255)->nullable()->after('awarded_quote_line_id');
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->timestamp('awarded_at')->nullable();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('awarded_by');
            $table->dropColumn('awarded_at');
        });

        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('awarded_quote_line_id');
            $table->dropColumn('award_reason');
        });

        Schema::dropIfExists('supplier_quote_lines');
        Schema::dropIfExists('supplier_quotes');
    }
};
