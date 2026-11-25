<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Location;
use App\Models\LocationStock;
use App\Models\Product;
use App\Services\InventoryValuation;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What is on the shelf, and what it is worth.
 *
 * Not the Product Master, which is where a product is described. This is
 * the warehouse view: quantities, FIFO value, and what is running low.
 *
 * The value comes from `InventoryValuation`, which the dashboard uses too,
 * so the two screens can never disagree about the figure.
 */
class StockController extends Controller
{
    public function __construct(protected InventoryValuation $valuation) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewStock', Product::class);

        $filters = $this->validatedFilters($request);

        $at = $filters['location_id'];

        // Narrowed to one location (phase 31.2), each row also carries what
        // sits there — one sub-select, so a page of forty is still one query
        // — and "In stock" and "Out of stock" ask about that location. Low
        // stock stays on the total: the reorder level is the product's.
        $here = fn ($q) => $q->where('location_id', $at)->where('on_hand', '>', 0);

        $query = $this->valuation->addTo(Product::query())
            ->with(['category:id,name', 'unit:id,code,allows_decimals'])
            ->when($at, fn ($q) => $q->addSelect(['at_location' => LocationStock::select('on_hand')
                ->whereColumn('location_stocks.product_id', 'products.id')
                ->where('location_stocks.location_id', $at)
                ->limit(1)]))
            ->when($filters['category_id'], fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['stock'] === 'low', fn ($q) => $q->lowStock())
            ->when($filters['stock'] === 'out', fn ($q) => $at
                ? $q->whereDoesntHave('locationStocks', $here)
                : $q->where('on_hand', 0))
            ->when($filters['stock'] === 'held', fn ($q) => $at
                ? $q->whereHas('locationStocks', $here)
                : $q->where('on_hand', '>', 0))
            ->when(
                $filters['status'] !== null,
                fn ($q) => $q->where('is_active', $filters['status'] === 'active')
            );

        $table = TableQuery::for($query, $request)
            ->searchable(['sku', 'name', 'category.name'])
            ->sortable(['sku', 'name', 'on_hand', 'reorder_level', 'stock_value'])
            ->defaultSort('sku')
            ->extra($filters);

        // Held for customer orders (phase 29): one grouped query, whatever
        // the page holds. Narrowed to a location, what orders hold there
        // (phase 31.4), and available is that location's figure less it.
        $held = Reservations::reserved(locationId: $at);
        // The part of it a pending loss report holds aside (phase 33), so the
        // screen can say which is which.
        $forLosses = Reservations::heldForLosses(locationId: $at);

        return Inertia::render('Stock/Index', [
            'title' => 'Stock on Hand',
            'stock' => $table->paginate()->through(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'photo_url' => $product->photoUrl(),
                'category' => $product->category?->name,
                'unit' => $product->unit?->code,
                'on_hand' => $product->on_hand,
                'at_location' => $at ? Quantity::normalize($product->at_location ?? 0) : null,
                'reserved' => Quantity::fromMilli($held[$product->id] ?? 0),
                'held_for_losses' => Quantity::fromMilli($forLosses[$product->id] ?? 0),
                'available' => Reservations::available($at ? Quantity::normalize($product->at_location ?? 0) : $product->on_hand, $held[$product->id] ?? 0),
                'reorder_level' => $product->reorder_level,
                'is_low_stock' => $product->isLowStock(),
                'is_active' => $product->is_active,
                // From the sub-select, so a page of forty costs one query.
                'stock_value' => number_format((float) $product->stock_value, 2, '.', ''),
            ]),
            'filters' => $table->state(),
            // The figures above the table are for everything, not only the
            // page on screen.
            'summary' => $this->valuation->summary(),
            'options' => [
                'locations' => Location::orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name])
                    ->all(),
                'categories' => Category::orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Category $c) => ['value' => $c->id, 'label' => $c->name])
                    ->all(),
                'stock' => [
                    ['value' => 'held', 'label' => 'In stock'],
                    ['value' => 'low', 'label' => 'At or below reorder level'],
                    ['value' => 'out', 'label' => 'Out of stock'],
                ],
                'statuses' => [
                    ['value' => 'active', 'label' => 'Active only'],
                    ['value' => 'inactive', 'label' => 'Inactive only'],
                ],
            ],
        ]);
    }

    /**
     * @return array{location_id:?int,category_id:?int,stock:?string,status:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'stock' => ['nullable', 'in:held,low,out'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        return [
            'location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'stock' => $validated['stock'] ?? null,
            'status' => $validated['status'] ?? null,
        ];
    }
}
