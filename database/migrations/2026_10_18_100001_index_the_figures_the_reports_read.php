<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One index, for the inventory valuation.
 *
 * Measured on a throwaway MySQL schema holding 20,000 sales, 8,000
 * deliveries, 60,000 sale lines, 40,000 batches and 90,000 ledger rows,
 * dated across three years, with `EXPLAIN` run on the SQL the code actually
 * issues rather than on queries rewritten by hand.
 *
 * `InventoryValuation` added up every batch ever created and named no column
 * to filter on, so nothing could be indexed: `type=ALL`, 39,824 rows,
 * 3.5-9.8 ms, on every dashboard and on every page of the Stock screen. The
 * service now asks only for batches with stock left — the same figure, since
 * a used-up batch is worth nothing — and this index covers both columns the
 * sum needs. It became `type=range ... Using index`, 4,444 rows, 0.6-1.7 ms:
 * five to ten times faster, and it no longer touches the table at all.
 *
 * Four composite `(status, date)` indexes were written here first and then
 * taken out again, which is worth recording so they are not re-added on the
 * same reasoning. The yearly cards, the monthly chart and the top-seller
 * list all filter on `status` and a date, and all four document tables
 * already carry a single-column index on each. None of those indexes gets
 * used, because nearly every document ends up `posted`, so status matches
 * almost the whole table. Adding the pair as one index changed nothing:
 * MySQL still chose a full scan, and it was right to — a quarter of the
 * table fetched row by row through an index costs more than one straight
 * pass. A narrow report range is already served by the plain date index,
 * where status would add no selectivity either. So the composites earned
 * nothing and would have cost a write on every document saved.
 *
 * Those figures are 1-2.5 ms for the cards and 12-17 ms for the top sellers
 * at this volume, cached for five minutes. If they ever do need help it will
 * be a covering index carrying `total_amount`, so the sum never reads the
 * table — but only once a screen is actually slow.
 *
 * Two other things were left alone deliberately: a document list sorted by
 * date still scans and sorts, because `TableQuery` adds `id` as a tiebreaker
 * and no index covers the pair; and `paginate()` runs its own `count(*)`,
 * 18 ms over 90,000 ledger rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_layers', function (Blueprint $table) {
            $table->index(['quantity_remaining', 'unit_cost'], 'layers_valuation');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_layers', function (Blueprint $table) {
            $table->dropIndex('layers_valuation');
        });
    }
};
