<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Http\Resources\MovementResource;
use App\Http\Resources\ProductResource;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\InventoryMetrics;
use App\Services\InventoryValuation;
use App\Support\ApiResponse;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The warehouse and the figures, read-only.
 *
 * Everything here reads the same services the web screens do, so a phone
 * and a browser can never be told two different things about the same
 * stock.
 */
class InventoryController extends Controller
{
    public function __construct(
        protected InventoryValuation $valuation,
        protected InventoryMetrics $metrics,
    ) {}

    public function movements(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryMovement::class);

        $filters = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', Rule::in(MovementType::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = InventoryMovement::query()
            ->with('product:id,sku,name')
            ->forProduct($filters['product_id'] ?? null)
            ->ofType($filters['type'] ?? null)
            ->betweenDates($filters['from'] ?? null, $filters['to'] ?? null);

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'product.sku'])
            // The id, not the date: posting order is date order, and a date
            // sort would make the running balance read backwards.
            ->sortable(['id', 'type', 'reference'])
            ->defaultSort('id', 'desc');

        return ApiResponse::paginated($table->paginate(), MovementResource::class);
    }

    public function stock(Request $request): JsonResponse
    {
        $this->authorize('viewStock', Product::class);

        $filters = $request->validate([
            'stock' => ['nullable', 'in:held,low,out'],
        ]);

        $query = $this->valuation->addTo(Product::query())
            ->with(['category:id,name', 'unit:id,code,allows_decimals'])
            ->when(($filters['stock'] ?? null) === 'low', fn ($q) => $q->lowStock())
            ->when(($filters['stock'] ?? null) === 'out', fn ($q) => $q->where('on_hand', 0))
            ->when(($filters['stock'] ?? null) === 'held', fn ($q) => $q->where('on_hand', '>', 0));

        $table = TableQuery::for($query, $request)
            ->searchable(['sku', 'name'])
            ->sortable(['sku', 'name', 'on_hand', 'stock_value'])
            ->defaultSort('sku');

        $page = $table->paginate();

        return ApiResponse::paginated(
            $page,
            ProductResource::class,
            // The figures above the table cover everything, not this page.
            ['summary' => $this->valuation->summary()],
        );
    }

    /**
     * The dashboard figures, gated exactly as the web screen gates them.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $seesSales = $request->user()->can('sales.view');
        $seesPurchases = $request->user()->can('purchases.view');
        $seesStock = $request->user()->can('stock.view');

        abort_unless($seesSales || $seesPurchases || $seesStock, 403);

        return ApiResponse::success([
            'cards' => $this->metrics->cards(
                $seesSales,
                $seesPurchases,
                $seesStock,
                $request->user()->can('sales.view_cost'),
            ),
            'trend' => $seesSales || $seesPurchases
                ? $this->metrics->monthlySeries($seesSales, $seesPurchases)
                : null,
            // The limits match the ones InventoryMetrics::flush() clears.
            'top_sellers' => $seesSales ? $this->metrics->topSellers(5) : null,
            'low_stock' => $seesStock ? $this->metrics->lowStock(5) : null,
        ]);
    }
}
