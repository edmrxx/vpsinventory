<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Support\DocumentLink;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every time stock moved, in one place.
 *
 * Read-only by design, like the audit trail: this controller has one method
 * and there are no write routes. The ledger is append-only, so a row here is
 * never corrected — it is answered by another movement.
 */
class MovementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = $this->validatedFilters($request);

        // What a sale cost is its cost of goods, so it is held back behind
        // the same permission that guards it on the sale itself.
        $seesCost = $request->user()->can('viewCost', Sale::class);
        $openable = DocumentLink::openableBy($request->user());

        $query = InventoryMovement::query()
            ->with('product:id,sku,name')
            ->forProduct($filters['product_id'])
            ->ofType($filters['type'])
            ->betweenDates($filters['from'], $filters['to']);

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'product.sku'])
            // The date is deliberately not sortable. TableQuery adds the
            // id ascending as its tiebreaker, so a date sort puts same-day
            // rows oldest first and the running balance reads backwards —
            // 5, 30, 35, 30 instead of 30, 35, 30, 5. Backdating is refused
            // everywhere, so the id already gives date order.
            ->sortable(['id', 'type', 'reference'])
            ->defaultSort('id', 'desc')
            ->extra($filters);

        return Inertia::render('Movements/Index', [
            'title' => 'Inventory Movement',
            'movements' => $table->paginate()->through(fn (InventoryMovement $movement) => array_merge([
                'id' => $movement->id,
                'movement_date' => $movement->movement_date?->format('d M Y'),
                'reference' => $movement->reference,
                'document_url' => DocumentLink::for($movement->source_type, $movement->source_id, $openable),
                'document_label' => DocumentLink::label($movement->source_type),
                'type' => $movement->type->value,
                'type_label' => $movement->type->label(),
                'type_tone' => $movement->type->tone(),
                'sku' => $movement->product?->sku,
                'product' => $movement->product?->name,
                'quantity_in' => $movement->quantity_in,
                'quantity_out' => $movement->quantity_out,
                'balance_after' => $movement->balance_after,
            ], $seesCost ? [
                'unit_cost' => $movement->unit_cost,
                'total_cost' => $movement->total_cost,
            ] : [])),
            'filters' => $table->state(),
            'options' => [
                'products' => $this->productOptions(),
                'types' => MovementType::options(),
            ],
            'can' => ['viewCost' => $seesCost],
        ]);
    }

    /**
     * @return array{product_id:?int,type:?string,from:?string,to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', Rule::in(MovementType::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'product_id' => isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            'type' => $validated['type'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }

    /**
     * Every product, switched off ones included: the history names them, so
     * filtering by one must stay possible.
     *
     * @return list<array{value:int,label:string,keywords:string,barcode:?string}>
     */
    protected function productOptions(): array
    {
        return Product::query()
            ->orderBy('sku')
            ->get(['id', 'sku', 'name', 'is_active', ...Product::SEARCH_COLUMNS])
            ->map(fn (Product $p) => [
                'value' => $p->id,
                'label' => $p->is_active ? "{$p->sku} — {$p->name}" : "{$p->sku} — {$p->name} (inactive)",
                // Found by brand, model or a scan, like every product picker (phase 30).
                'keywords' => $p->searchWords(),
                'barcode' => $p->barcode,
            ])
            ->all();
    }
}
