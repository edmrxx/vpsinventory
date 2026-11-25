<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LookupResource;
use App\Http\Resources\PartyResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Support\ApiResponse;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The master files, read-only.
 *
 * Five modules in one controller because the shape is identical: list with
 * search, sort and paging, or fetch one. Writing stays on the web for now;
 * adding it means reusing the Form Request and Policy that already exist.
 */
class CatalogController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $request->validate(['barcode' => ['nullable', 'string', 'max:64']]);

        $table = TableQuery::for(
            Product::query()
                ->with(['category:id,name', 'unit:id,code,allows_decimals', 'otherUnits.unit:id,code,allows_decimals', 'preferredSupplier:id,name'])
                // A phone that scanned a label asks for exactly that product
                // (phase 30). Stored in capitals, so the scan is folded too.
                ->when(
                    $request->filled('barcode'),
                    fn ($q) => $q->where('barcode', mb_strtoupper(trim((string) $request->query('barcode'))))
                ),
            $request,
        )
            ->searchable(['sku', 'barcode', 'name', 'brand', 'model', 'description', 'category.name'])
            ->sortable(['sku', 'name', 'brand', 'cost', 'selling_price', 'on_hand', 'is_active', 'created_at'])
            ->defaultSort('sku');

        return ApiResponse::paginated($table->paginate(), ProductResource::class);
    }

    public function product(Request $request, Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        $product->load(['category:id,name', 'unit:id,code,allows_decimals', 'otherUnits.unit:id,code,allows_decimals', 'preferredSupplier:id,name']);

        return ApiResponse::resource(new ProductResource($product));
    }

    public function categories(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $table = TableQuery::for(Category::query()->withCount('products'), $request)
            ->searchable(['name', 'description'])
            ->sortable(['name', 'is_active', 'created_at'])
            ->defaultSort('name');

        return ApiResponse::paginated($table->paginate(), LookupResource::class);
    }

    public function units(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Unit::class);

        $table = TableQuery::for(Unit::query()->withCount('products'), $request)
            ->searchable(['code', 'name'])
            ->sortable(['code', 'name', 'is_active', 'created_at'])
            ->defaultSort('code');

        return ApiResponse::paginated($table->paginate(), LookupResource::class);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $table = TableQuery::for(Supplier::query(), $request)
            ->searchable(['code', 'name', 'contact_person', 'email', 'phone'])
            ->sortable(['code', 'name', 'is_active', 'created_at'])
            ->defaultSort('name');

        return ApiResponse::paginated($table->paginate(), PartyResource::class);
    }

    public function supplier(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);

        return ApiResponse::resource(new PartyResource($supplier));
    }

    public function customers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $table = TableQuery::for(Customer::query()->withBalance(), $request)
            ->searchable(['code', 'name', 'contact_person', 'email', 'phone'])
            ->sortable(['code', 'name', 'is_active', 'created_at'])
            ->defaultSort('name');

        return ApiResponse::paginated($table->paginate(), PartyResource::class);
    }

    public function customer(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return ApiResponse::resource(new PartyResource($customer));
    }
}
