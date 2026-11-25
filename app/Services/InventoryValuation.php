<?php

namespace App\Services;

use App\Models\InventoryLayer;
use App\Models\Product;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the stock on the shelf is worth.
 *
 * FIFO means a product has no single cost: it has batches, each bought at
 * its own price. The value is therefore what is left of every batch,
 * multiplied by what that batch cost — never a quantity times a guess.
 *
 * One service, because the Stock screen and the dashboard's inventory card
 * must never disagree about the figure.
 */
class InventoryValuation
{
    /**
     * Add the value of the open batches to a product query.
     *
     * A sub-select, not `withSum`: the value is two columns multiplied
     * together, and `withSum` can only add one up. Done this way a page of
     * forty products still costs one query.
     */
    public function addTo(Builder $query): Builder
    {
        return $query->select('products.*')->selectSub(
            InventoryLayer::query()
                ->selectRaw('COALESCE(SUM(quantity_remaining * unit_cost), 0)')
                ->whereColumn('inventory_layers.product_id', 'products.id')
                // The open batches only. A used-up batch adds nothing to
                // the sum, so this changes no figure — it just stops the
                // database reading the history of every product that ever
                // sold. See `total()` for the measurement.
                ->where('quantity_remaining', '>', 0),
            'stock_value',
        );
    }

    /**
     * What everything on the shelf is worth.
     */
    public function total(): string
    {
        $total = InventoryLayer::query()
            ->selectRaw('COALESCE(SUM(quantity_remaining * unit_cost), 0) AS total')
            // Without this the query names no column to filter on, so
            // nothing can be indexed and MySQL reads the whole table: a
            // full scan of 40,000 batches on a scratch copy, on every
            // dashboard and every page of the Stock screen. A batch with
            // nothing left contributes zero to the sum, so asking only for
            // the open ones gives the same figure from a range scan over
            // the `layers_valuation` index, which covers both columns.
            // `quantity_remaining` is unsigned, so there is nothing below
            // zero to miss.
            ->where('quantity_remaining', '>', 0)
            ->value('total');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * What the shelf is worth, narrowed to a category or one product.
     *
     * The dashboard can be filtered; the Stock screen cannot. Rather than
     * work the value out a second time somewhere else, the one query that
     * knows how a batch is valued takes the filter here.
     *
     * @param  array{category_id?:?int, product_id?:?int}  $filters
     * @return array{value:string, units:int, products:int, low_stock:int, out_of_stock:int}
     */
    public function snapshot(array $filters = []): array
    {
        $narrow = function ($query) use ($filters) {
            if ($filters['category_id'] ?? null) {
                $query->where('products.category_id', $filters['category_id']);
            }

            if ($filters['product_id'] ?? null) {
                $query->where('products.id', $filters['product_id']);
            }

            return $query;
        };

        $value = $narrow(
            InventoryLayer::query()
                ->join('products', 'products.id', '=', 'inventory_layers.product_id')
                ->where('quantity_remaining', '>', 0)
        )->selectRaw('COALESCE(SUM(quantity_remaining * inventory_layers.unit_cost), 0) AS total')
            ->value('total');

        $counts = $narrow(Product::query())
            ->selectRaw('COUNT(*) AS products')
            ->selectRaw('COALESCE(SUM(on_hand), 0) AS units')
            ->selectRaw(
                'SUM(CASE WHEN reorder_level > 0 AND on_hand <= reorder_level THEN 1 ELSE 0 END) AS low_stock'
            )
            ->selectRaw('SUM(CASE WHEN on_hand = 0 THEN 1 ELSE 0 END) AS out_of_stock')
            ->where('is_active', true)
            ->first();

        return [
            'value' => number_format((float) $value, 2, '.', ''),
            'units' => Quantity::normalize($counts->units ?? 0),
            'products' => (int) ($counts->products ?? 0),
            'low_stock' => (int) ($counts->low_stock ?? 0),
            'out_of_stock' => (int) ($counts->out_of_stock ?? 0),
        ];
    }

    /**
     * What the shelf is worth, split by category.
     *
     * Open batches only, the same rule `total()` follows, so the bars add
     * up to the inventory figure stated everywhere else.
     *
     * @return array{labels: list<string>, series: list<float>, total: string}
     */
    public function valueByCategory(array $filters = []): array
    {
        $rows = InventoryLayer::query()
            ->join('products', 'products.id', '=', 'inventory_layers.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('quantity_remaining', '>', 0)
            ->when(
                $filters['category_id'] ?? null,
                fn ($q, $id) => $q->where('products.category_id', $id)
            )
            ->when(
                $filters['product_id'] ?? null,
                fn ($q, $id) => $q->where('products.id', $id)
            )
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('COALESCE(categories.name, \'Uncategorised\') AS label')
            ->selectRaw('SUM(quantity_remaining * inventory_layers.unit_cost) AS amount')
            ->orderByDesc('amount')
            ->get();

        return [
            'labels' => $rows->pluck('label')->all(),
            'series' => $rows->map(fn ($row) => round((float) $row->amount, 2))->all(),
            'total' => number_format((float) $rows->sum('amount'), 2, '.', ''),
        ];
    }

    /**
     * The figures above the stock table.
     *
     * @return array{products:int,units:int,value:string,low_stock:int}
     */
    public function summary(): array
    {
        // Units and value must mean the same thing. A product can be
        // switched off while it still holds stock, so counting units over
        // the active ones alone would leave those units out while their
        // value stayed in — two cards disagreeing side by side.
        $units = Quantity::normalize(Product::query()->sum('on_hand'));

        $active = Product::query()
            ->selectRaw('COUNT(*) AS products')
            ->selectRaw(
                'SUM(CASE WHEN reorder_level > 0 AND on_hand <= reorder_level THEN 1 ELSE 0 END) AS low_stock'
            )
            ->where('is_active', true)
            ->first();

        return [
            'products' => (int) ($active->products ?? 0),
            'units' => $units,
            'value' => $this->total(),
            'low_stock' => (int) ($active->low_stock ?? 0),
        ];
    }

    /**
     * The open batches behind one product, oldest first.
     *
     * This is the FIFO queue itself: the next sale eats the top of it.
     *
     * @return list<array<string, mixed>>
     */
    public function batchesFor(Product $product): array
    {
        $batches = InventoryLayer::query()
            ->where('product_id', $product->id)
            ->where('quantity_remaining', '>', 0)
            // Newest first, to read the same way as the stock card beside
            // it. The FIFO pick is a different query — see
            // InventoryLayer::scopeFifo — so this order is for the eye
            // only and cannot change what a sale costs.
            ->orderByDesc('id')
            ->get();

        // Which batch the next sale will eat. It is the oldest, so with the
        // newest on top it is the last row, and it has to be marked or the
        // reader loses the one thing this panel is for.
        $nextId = $batches->last()?->id;

        return $batches
            ->map(fn (InventoryLayer $layer) => [
                'id' => $layer->id,
                'reference' => $layer->reference,
                'layer_date' => $layer->layer_date?->format('d M Y'),
                'quantity_received' => $layer->quantity_received,
                'quantity_remaining' => $layer->quantity_remaining,
                'unit_cost' => $layer->unit_cost,
                'value' => $layer->remainingValue(),
                'is_next' => $layer->id === $nextId,
            ])
            ->all();
    }

    /**
     * What one product's stock is worth.
     */
    public function forProduct(Product $product): string
    {
        $value = DB::table('inventory_layers')
            ->where('product_id', $product->id)
            ->selectRaw('COALESCE(SUM(quantity_remaining * unit_cost), 0) AS total')
            ->value('total');

        return number_format((float) $value, 2, '.', '');
    }
}
