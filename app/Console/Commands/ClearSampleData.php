<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\LossReport;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Services\AuditService;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Take the sample rows back out again.
 *
 * A sample product holds stock and carries a ledger behind it, and the
 * screens refuse to delete either, so this is the only way to take the
 * samples back out until the adjustment module arrives.
 *
 * It unwinds in the order the foreign keys allow: the batches and movements
 * first, then the opening document that created them, then the products, and
 * last the categories they sat under.
 *
 * It matches on SKU and code, so a sample row the user has since edited into
 * one of their own is still removed. The SKUs are printed before the prompt
 * for that reason.
 */
class ClearSampleData extends Command
{
    protected $signature = 'sample:clear {--force : Do not ask first}';

    protected $description = 'Remove the rows added by SampleDataSeeder';

    public function handle(): int
    {
        $skus = array_keys(SampleDataSeeder::PRODUCTS);
        $codes = array_keys(SampleDataSeeder::SUPPLIERS);
        $names = array_keys(SampleDataSeeder::CATEGORIES);

        $products = Product::whereIn('sku', $skus)->count();
        $suppliers = Supplier::whereIn('code', $codes)->count();

        if ($products === 0 && $suppliers === 0) {
            $this->components->info('There is no sample data to remove.');

            return self::SUCCESS;
        }

        // A document the user posted is theirs, and it names these rows.
        // Removing them would either break the foreign keys or quietly take
        // real records away, so the command stops and says which.
        if ($blocking = $this->blockingDocuments($skus, $codes)) {
            $this->components->error(
                'The sample rows are named on '.count($blocking).' document(s), so they cannot be removed.'
            );

            $this->components->bulletList($blocking);

            $this->components->warn(
                'Void or keep those documents first. Nothing was removed.'
            );

            return self::FAILURE;
        }

        $this->components->info("Found {$products} sample product(s) and {$suppliers} sample supplier(s).");

        $this->components->bulletList([
            'Products: '.implode(', ', $skus),
            'Suppliers: '.implode(', ', $codes),
            'Categories: '.implode(', ', $names).' (kept if other products are filed under them)',
        ]);

        $this->components->warn(
            'These are matched by SKU and code. A row you have since edited into one of '
                .'your own products is removed as well.'
        );

        if (! $this->option('force') && ! $this->confirm('Remove them?', false)) {
            $this->components->warn('Nothing was removed.');

            return self::SUCCESS;
        }

        app(AuditService::class)->withoutAuditing(function () use ($skus, $codes, $names) {
            DB::transaction(function () use ($skus, $codes, $names) {
                $productIds = Product::whereIn('sku', $skus)->pluck('id');

                // Unwound in the order the foreign keys allow. Every one of
                // these points at the product, so all of them go first.
                //
                // The consumption rows cascade from their movement, so
                // deleting the movements takes them with it.
                InventoryMovement::whereIn('product_id', $productIds)->delete();
                InventoryLayer::whereIn('product_id', $productIds)->delete();
                InventoryAdjustmentLine::whereIn('product_id', $productIds)->delete();

                // The opening document the seeder posted is now empty.
                $this->removeEmptyAdjustments();

                // A query-builder delete runs no policy, so neither the stock
                // nor the history stands in the way here.
                Product::whereIn('id', $productIds)->delete();

                // A category is only removed once nothing is filed under it,
                // so one the user has since used for their own products stays.
                foreach ($names as $name) {
                    $category = Category::where('name', $name)->withCount('products')->first();

                    if ($category && $category->products_count === 0) {
                        $category->delete();

                        continue;
                    }

                    if ($category) {
                        $this->components->warn(
                            "Category [{$name}] was kept: {$category->products_count} other product(s) are filed under it."
                        );
                    }
                }

                Supplier::whereIn('code', $codes)->delete();
            });
        });

        $this->components->info('The sample data was removed.');

        return self::SUCCESS;
    }

    /**
     * Documents that name a sample product, supplier or customer.
     *
     * @param  list<string>  $skus
     * @param  list<string>  $codes
     * @return list<string>|null
     */
    protected function blockingDocuments(array $skus, array $codes): ?array
    {
        $purchases = Purchase::query()
            ->where(fn ($q) => $q
                ->whereHas('supplier', fn ($s) => $s->whereIn('code', $codes))
                ->orWhereHas('lines.product', fn ($p) => $p->whereIn('sku', $skus)))
            ->orderBy('reference')
            ->get()
            ->map(fn (Purchase $p) => "{$p->reference} of {$p->purchase_date->format('d M Y')}");

        $sales = Sale::query()
            ->whereHas('lines.product', fn ($p) => $p->whereIn('sku', $skus))
            ->orderBy('reference')
            ->get()
            ->map(fn (Sale $s) => "{$s->reference} of {$s->sale_date->format('d M Y')}");

        $salesReturns = SalesReturn::query()
            ->whereHas('lines.product', fn ($p) => $p->whereIn('sku', $skus))
            ->orderBy('reference')
            ->get()
            ->map(fn (SalesReturn $r) => "{$r->reference} of {$r->return_date->format('d M Y')}");

        $purchaseReturns = PurchaseReturn::query()
            ->whereHas('lines.product', fn ($p) => $p->whereIn('sku', $skus))
            ->orderBy('reference')
            ->get()
            ->map(fn (PurchaseReturn $r) => "{$r->reference} of {$r->return_date->format('d M Y')}");

        // The seeder's own opening document is the one with nobody behind
        // it. Every other adjustment is the user's, and theirs must never
        // be removed without a word.
        $adjustments = InventoryAdjustment::query()
            ->whereNotNull('posted_by')
            ->whereHas('lines.product', fn ($p) => $p->whereIn('sku', $skus))
            ->orderBy('reference')
            ->get()
            ->map(fn (InventoryAdjustment $a) => "{$a->reference} of {$a->adjustment_date->format('d M Y')}");

        // A transfer moves sample stock between places (phase 31.5): the
        // user's own document, never removed without a word.
        $transfers = StockTransfer::query()
            ->whereHas('lines.product', fn ($p) => $p->whereIn('sku', $skus))
            ->orderBy('reference')
            ->get()
            ->map(fn (StockTransfer $t) => "{$t->reference} of {$t->transfer_date->format('d M Y')}");

        // A count sheet lists sample products (phase 32): the user's own
        // work, open or finished, never removed without a word.
        $counts = StockCount::query()
            ->whereHas('lines.product', fn ($p) => $p->whereIn('sku', $skus))
            ->orderBy('reference')
            ->get()
            ->map(fn (StockCount $c) => "{$c->reference} of {$c->count_date->format('d M Y')}");

        // A loss report names sample products (phase 33): the user's own
        // report, answered or not, never removed without a word.
        $losses = LossReport::query()
            ->whereHas('lines.product', fn ($p) => $p->whereIn('sku', $skus))
            ->orderBy('reference')
            ->get()
            ->map(fn (LossReport $r) => "{$r->reference} of {$r->report_date->format('d M Y')}");

        $all = $purchases
            ->concat($sales)
            ->concat($salesReturns)
            ->concat($purchaseReturns)
            ->concat($adjustments)
            ->concat($transfers)
            ->concat($counts)
            ->concat($losses)
            ->values()
            ->all();

        return $all === [] ? null : $all;
    }

    /**
     * Drop any adjustment left with no lines, which is what the seeder's
     * opening document becomes once its lines have gone.
     *
     * An adjustment that still holds lines belongs to the user and stays.
     */
    protected function removeEmptyAdjustments(): void
    {
        $empty = InventoryAdjustment::query()->whereDoesntHave('lines')->get();

        $empty->each(fn (InventoryAdjustment $adjustment) => $adjustment->deleteQuietly());

        if ($empty->isNotEmpty()) {
            $this->components->info(
                'Removed '.$empty->count().' empty adjustment document(s): '
                    .$empty->pluck('reference')->implode(', ').'.'
            );
        }
    }
}
