<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\InventoryAdjustment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Support\ApiResponse;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The documents, read-only.
 *
 * A list never loads its lines — forty documents of ten lines each would be
 * four hundred rows nobody asked for — so the list carries a count and the
 * single-record endpoint carries the lines themselves.
 *
 * Posting through the API is deliberately not here. A document moves stock
 * the moment it is saved, and that deserves the idempotency key and the
 * confirmation the web form already has. It is a follow-up, not an
 * afterthought.
 */
class DocumentController extends Controller
{
    public function purchases(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Purchase::class);

        $table = TableQuery::for(
            Purchase::query()->with(['supplier:id,name', 'location:id,name'])->withCount('lines'),
            $request,
        )
            ->searchable(['reference', 'supplier_reference', 'supplier.name'])
            ->sortable(['reference', 'purchase_date', 'total_amount', 'status'])
            ->defaultSort('purchase_date', 'desc');

        return ApiResponse::paginated($table->paginate(), DocumentResource::class);
    }

    public function purchase(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('view', $purchase);

        $purchase->load(['supplier:id,name', 'location:id,name', 'lines.product:id,sku,name,unit_id', 'lines.product.unit:id,code', 'lines.unit:id,code']);

        return ApiResponse::resource(new DocumentResource($purchase));
    }

    public function sales(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        $table = TableQuery::for(
            Sale::query()->with(['customer:id,name', 'location:id,name'])->withCount('lines'),
            $request,
        )
            ->searchable(['reference', 'customer.name'])
            ->sortable(['reference', 'sale_date', 'total_amount', 'status'])
            ->defaultSort('sale_date', 'desc');

        return ApiResponse::paginated($table->paginate(), DocumentResource::class);
    }

    public function sale(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);

        $sale->load(['customer:id,name', 'location:id,name', 'lines.product:id,sku,name,unit_id', 'lines.product.unit:id,code', 'lines.unit:id,code', 'payments.method:id,name,kind']);

        return ApiResponse::resource(new DocumentResource($sale));
    }

    public function salesReturns(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SalesReturn::class);

        $table = TableQuery::for(SalesReturn::query()->withCount('lines'), $request)
            ->searchable(['reference', 'reason'])
            ->sortable(['reference', 'return_date', 'total_amount'])
            ->defaultSort('return_date', 'desc');

        return ApiResponse::paginated($table->paginate(), DocumentResource::class);
    }

    public function salesReturn(Request $request, SalesReturn $salesReturn): JsonResponse
    {
        $this->authorize('view', $salesReturn);

        $salesReturn->load(['lines.product:id,sku,name']);

        return ApiResponse::resource(new DocumentResource($salesReturn));
    }

    public function purchaseReturns(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseReturn::class);

        $table = TableQuery::for(
            PurchaseReturn::query()->with('purchase:id,vat_rate')->withCount('lines'),
            $request
        )
            ->searchable(['reference', 'reason'])
            ->sortable(['reference', 'return_date', 'total_cost'])
            ->defaultSort('return_date', 'desc');

        return ApiResponse::paginated($table->paginate(), DocumentResource::class);
    }

    public function purchaseReturn(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorize('view', $purchaseReturn);

        $purchaseReturn->load(['purchase:id,vat_rate', 'lines.product:id,sku,name']);

        return ApiResponse::resource(new DocumentResource($purchaseReturn));
    }

    public function adjustments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryAdjustment::class);

        $table = TableQuery::for(InventoryAdjustment::query()->with('location:id,name')->withCount('lines'), $request)
            ->searchable(['reference', 'reason', 'notes'])
            ->sortable(['reference', 'adjustment_date', 'kind', 'status', 'total_cost'])
            ->defaultSort('adjustment_date', 'desc');

        return ApiResponse::paginated($table->paginate(), DocumentResource::class);
    }

    public function adjustment(Request $request, InventoryAdjustment $adjustment): JsonResponse
    {
        $this->authorize('view', $adjustment);

        $adjustment->load(['location:id,name', 'lines.product:id,sku,name']);

        return ApiResponse::resource(new DocumentResource($adjustment));
    }
}
