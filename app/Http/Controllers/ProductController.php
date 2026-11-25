<?php

namespace App\Http\Controllers;

use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\AuditService;
use App\Services\InventoryValuation;
use App\Support\DocumentLink;
use App\Support\ProductPhoto;
use App\Support\Quantity;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The product master.
 *
 * Stock never arrives through this controller. `on_hand` is outside the
 * model's `$fillable` and outside both form requests, so it can only move
 * through a posted document in the inventory ledger.
 */
class ProductController extends Controller
{
    public function __construct(
        protected AuditService $audit,
        protected InventoryValuation $valuation,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $filters = $this->validatedFilters($request);

        $query = Product::query()
            ->with(['category:id,name', 'unit:id,code,allows_decimals', 'otherUnits.unit:id,code', 'preferredSupplier:id,name'])
            // The delete rule asks how often this product has moved. Loading
            // it here keeps the policy from running one query per row.
            ->withCount(['movements', 'purchaseRequestLines', 'purchaseOrderLines', 'quotationLines', 'salesOrderLines', 'stockCountLines', 'lossReportLines', 'pendingPaymentLines'])
            ->when($filters['category_id'], fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['unit_id'], fn ($q, $id) => $q->where('unit_id', $id))
            ->when($filters['brand'], fn ($q, $brand) => $q->where('brand', $brand))
            ->when($filters['supplier_id'], fn ($q, $id) => $q->where('preferred_supplier_id', $id))
            ->when(
                $filters['status'] !== null,
                fn ($q) => $q->where('is_active', $filters['status'] === 'active')
            )
            ->when($filters['stock'] === 'low', fn ($q) => $q->lowStock())
            ->when($filters['stock'] === 'out', fn ($q) => $q->where('on_hand', 0));

        $table = TableQuery::for($query, $request)
            // A barcode scanned into the search box finds its product.
            ->searchable(['sku', 'barcode', 'name', 'brand', 'model', 'description', 'category.name'])
            ->sortable([
                'sku', 'name', 'brand', 'cost', 'selling_price',
                'on_hand', 'reorder_level', 'is_active', 'created_at',
            ])
            ->defaultSort('sku')
            ->extra($filters);

        return Inertia::render('Products/Index', [
            'title' => 'Product Master',
            'products' => $table->paginate()->through(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'brand' => $product->brand,
                'model' => $product->model,
                'description' => $product->description,
                'photo_url' => $product->photoUrl(),
                'category' => $product->category?->name,
                'category_id' => $product->category_id,
                'unit' => $product->unit?->code,
                'unit_id' => $product->unit_id,
                'preferred_supplier_id' => $product->preferred_supplier_id,
                'preferred_supplier' => $product->preferredSupplier?->name,
                'cost' => $product->cost,
                'selling_price' => $product->selling_price,
                'on_hand' => $product->on_hand,
                'reorder_level' => $product->reorder_level,
                'is_low_stock' => $product->isLowStock(),
                'is_active' => $product->is_active,
                // The edit dialog opens from this row with no request of
                // its own, so the other units ride along.
                'other_units' => $this->otherUnitsOf($product),
                'can' => [
                    'update' => $request->user()->can('update', $product),
                    'delete' => $request->user()->can('delete', $product),
                    'status' => $request->user()->can('updateStatus', $product),
                ],
            ]),
            'filters' => $table->state(),
            'options' => $this->filterOptions(),
            'can' => ['create' => $request->user()->can('create', Product::class)],
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $values = $request->safe()->except(['photo', 'units']);

        if ($request->hasFile('photo')) {
            $values['photo_path'] = ProductPhoto::store($request->file('photo'));
        }

        $product = DB::transaction(function () use ($values, $request) {
            $product = Product::create($values);
            $product->syncOtherUnits($request->validated('units', []));

            return $product;
        });

        return back()->with('success', "Product [{$product->sku}] was created.");
    }

    public function show(Request $request, Product $product): Response
    {
        $this->authorize('view', $product);

        $product->auditView();

        return Inertia::render('Products/Show', array_merge(
            ['title' => $product->name, 'options' => $this->filterOptions()],
            $this->detail($request, $product),
        ));
    }

    /**
     * The same product, as JSON, for the dialog on the Stock screen.
     *
     * Both this and `show()` call `detail()`, so the dialog cannot show a
     * different figure from the page. The filter options are left out: the
     * dialog only reads, it does not edit.
     */
    public function preview(Request $request, Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        $product->auditView();

        return response()->json($this->detail($request, $product));
    }

    /**
     * Everything a product shows: the record, its batches and its stock card.
     *
     * @return array<string, mixed>
     */
    protected function detail(Request $request, Product $product): array
    {
        $product->load(['category:id,name', 'unit:id,code,name,allows_decimals', 'otherUnits.unit:id,code', 'preferredSupplier:id,name,is_active']);

        // One product, so these are single-record queries.
        $seesCost = $request->user()->can('viewCost', Sale::class);
        $readsMovements = $request->user()->can('movements.view');
        $openable = DocumentLink::openableBy($request->user());
        $readsStock = $request->user()->can('viewStock', Product::class);

        return [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'brand' => $product->brand,
                'model' => $product->model,
                'description' => $product->description,
                'photo_url' => $product->photoUrl(),
                'category' => $product->category?->name,
                'category_id' => $product->category_id,
                'unit' => $product->unit?->code,
                'unit_id' => $product->unit_id,
                'unit_name' => $product->unit?->name,
                'preferred_supplier_id' => $product->preferred_supplier_id,
                'preferred_supplier' => $product->preferredSupplier?->name,
                'preferred_supplier_active' => $product->preferredSupplier?->is_active,
                'cost' => $product->cost,
                'selling_price' => $product->selling_price,
                'on_hand' => $product->on_hand,
                'reorder_level' => $product->reorder_level,
                'is_low_stock' => $product->isLowStock(),
                'is_active' => $product->is_active,
                'other_units' => $this->otherUnitsOf($product),
                'created_at' => $product->created_at?->format('d M Y, g:i A'),
                'updated_at' => $product->updated_at?->format('d M Y, g:i A'),
            ],
            'options' => $this->filterOptions(),
            // The stock card: the last handful of moves, and the batches
            // the next sale will eat into.
            'movements' => $readsMovements
                ? $product->movements()
                    ->orderByDesc('id')
                    ->limit(15)
                    ->get()
                    ->map(fn (InventoryMovement $movement) => array_merge([
                        'id' => $movement->id,
                        'movement_date' => $movement->movement_date?->format('d M Y'),
                        'reference' => $movement->reference,
                        'document_url' => DocumentLink::for(
                            $movement->source_type,
                            $movement->source_id,
                            $openable,
                        ),
                        'type_label' => $movement->type->label(),
                        'type_tone' => $movement->type->tone(),
                        'quantity_in' => $movement->quantity_in,
                        'quantity_out' => $movement->quantity_out,
                        'balance_after' => $movement->balance_after,
                    ], $seesCost ? ['unit_cost' => $movement->unit_cost] : []))
                    ->all()
                : [],
            'batches' => $readsStock ? $this->valuation->batchesFor($product) : [],
            // Where it sits (phase 31.2): every active location, and any
            // switched-off one still holding some. Behind the same gate as
            // the batches.
            'locations' => $readsStock ? $this->stockByLocation($product) : [],
            'stockValue' => $readsStock ? $this->valuation->forProduct($product) : null,
            'can' => [
                'update' => $request->user()->can('update', $product),
                'delete' => $request->user()->can('delete', $product),
                'viewMovements' => $readsMovements,
                'viewCost' => $seesCost,
            ],
        ];
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $values = $request->safe()->except(['photo', 'remove_photo', 'units']);

        // A new picture replaces whatever was there. Ticking "remove" with
        // no new file takes the old one off. Sending neither leaves the
        // photo exactly as it was, which is what an ordinary edit does.
        if ($request->hasFile('photo')) {
            $values['photo_path'] = ProductPhoto::replace($product->photo_path, $request->file('photo'));
        } elseif ($request->boolean('remove_photo')) {
            ProductPhoto::delete($product->photo_path);

            $values['photo_path'] = null;
        }

        DB::transaction(function () use ($product, $values, $request) {
            $product->update($values);
            $product->syncOtherUnits($request->validated('units', []));
        });

        return back()->with('success', "Product [{$product->sku}] was saved.");
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $sku = $product->sku;
        $photo = $product->photo_path;

        $product->delete();

        // Only once the row is gone, so a refused delete never takes the
        // picture with it.
        ProductPhoto::delete($photo);

        return redirect()
            ->route('products.index')
            ->with('success', "Product [{$sku}] was deleted.");
    }

    public function updateStatus(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('updateStatus', $product);

        $this->audit->withoutAuditing(
            fn () => $product->update(['is_active' => ! $product->is_active])
        );

        $product->auditStatus($product->is_active);

        return back()->with(
            'success',
            $product->is_active
                ? "Product [{$product->sku}] is active again."
                : "Product [{$product->sku}] is now inactive."
        );
    }

    /**
     * What this product has at each location, the default first.
     *
     * @return list<array{location:string,type:string,is_default:bool,on_hand:int|float}>
     */
    protected function stockByLocation(Product $product): array
    {
        $held = $product->locationStocks()->pluck('on_hand', 'location_id');

        return Location::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_default', 'is_active'])
            ->filter(fn (Location $l) => $l->is_active || Quantity::toMilli($held[$l->id] ?? 0) !== 0)
            ->map(fn (Location $l) => [
                'location' => $l->name,
                'type' => $l->type->label(),
                'is_default' => $l->is_default,
                'on_hand' => Quantity::normalize($held[$l->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * The other units, as the edit form and the product page read them.
     *
     * `price` is what one actually sells for: its own price, or the piece
     * price times the factor when none was set.
     *
     * @return list<array{unit_id:int, code:?string, factor:int|float, selling_price:?string, price:string}>
     */
    protected function otherUnitsOf(Product $product): array
    {
        return $product->otherUnits
            ->map(fn (ProductUnit $other) => [
                'unit_id' => (int) $other->unit_id,
                'code' => $other->unit?->code,
                'factor' => $other->factor,
                'selling_price' => $other->selling_price,
                'price' => $other->priceFrom($product->selling_price),
            ])
            ->all();
    }

    /**
     * The filters above the table.
     *
     * @return array{category_id:?int,unit_id:?int,brand:?string,supplier_id:?int,status:?string,stock:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'brand' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'status' => ['nullable', 'in:active,inactive'],
            'stock' => ['nullable', 'in:low,out'],
        ]);

        return [
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'unit_id' => isset($validated['unit_id']) ? (int) $validated['unit_id'] : null,
            'brand' => isset($validated['brand']) && $validated['brand'] !== '' ? $validated['brand'] : null,
            'supplier_id' => isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            'status' => $validated['status'] ?? null,
            'stock' => $validated['stock'] ?? null,
        ];
    }

    /**
     * Every category, unit, supplier and brand, once, for both the filter bar
     * and the form.
     *
     * The filter may name a row that is switched off, because older products
     * still point at it. The form shows only the active ones, plus whichever
     * row the product being edited already holds — the screen works that out
     * from `is_active`, so this stays four queries however it is used.
     *
     * The brands are the ones already typed, offered as suggestions so
     * "Boysen" is not also saved as "BOYSEN" and "Boysen " (phase 30).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function filterOptions(): array
    {
        return [
            'categories' => Category::orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (Category $c) => [
                    'value' => $c->id,
                    'label' => $c->name,
                    'is_active' => $c->is_active,
                ])
                ->all(),
            'units' => Unit::orderBy('code')
                ->get(['id', 'code', 'name', 'allows_decimals', 'is_active'])
                ->map(fn (Unit $u) => [
                    'value' => $u->id,
                    'label' => $u->code,
                    'name' => $u->name,
                    'allows_decimals' => $u->allows_decimals,
                    'is_active' => $u->is_active,
                ])
                ->all(),
            'suppliers' => Supplier::orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (Supplier $s) => [
                    'value' => $s->id,
                    'label' => $s->name,
                    'is_active' => $s->is_active,
                ])
                ->all(),
            'brands' => Product::query()
                ->whereNotNull('brand')
                ->distinct()
                ->orderBy('brand')
                ->pluck('brand')
                ->map(fn (string $brand) => ['value' => $brand, 'label' => $brand])
                ->all(),
        ];
    }
}
