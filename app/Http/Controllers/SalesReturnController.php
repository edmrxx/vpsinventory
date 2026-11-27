<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryException;
use App\Http\Requests\SalesReturn\StoreSalesReturnRequest;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Services\SalesReturnPoster;
use App\Support\Quantity;
use App\Support\TableQuery;
use App\Support\UnitConversion;
use App\Support\Vat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods a customer brought back.
 *
 * A return always starts from the sale it undoes — there is no way to make
 * one out of thin air — so `create` takes a sale and shows what is still
 * returnable on each of its lines.
 *
 * There is no void here, and no edit or delete. Undoing an undo leaves a
 * history nobody can follow; a mistake is corrected with another return or
 * with an adjustment.
 */
class SalesReturnController extends Controller
{
    public function __construct(protected SalesReturnPoster $poster) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SalesReturn::class);

        $filters = $this->validatedFilters($request);
        $seesCost = $request->user()->can('viewCost', Sale::class);

        $query = SalesReturn::query()
            ->with('sale:id,reference', 'sale.customer:id,name')
            ->withCount('lines')
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('return_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('return_date', '<=', $to));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'reason', 'sale.reference'])
            ->sortable(['reference', 'return_date', 'total_amount', 'created_at'])
            ->defaultSort('return_date', 'desc')
            ->extra($filters);

        return Inertia::render('SalesReturns/Index', [
            'title' => 'Sales Returns',
            'returns' => $table->paginate()->through(fn (SalesReturn $return) => array_merge([
                'id' => $return->id,
                'reference' => $return->reference,
                'sale_reference' => $return->sale?->reference,
                'sale_id' => $return->sale_id,
                'customer' => $return->sale?->customer?->name,
                'return_date' => $return->return_date?->format('d M Y'),
                'lines_count' => $return->lines_count,
                'reason' => $return->reason,
                'total_amount' => $return->total_amount,
            ], $seesCost ? ['total_cost' => $return->total_cost] : [])),
            'filters' => $table->state(),
            'can' => ['viewCost' => $seesCost],
        ]);
    }

    /**
     * The form, built from the sale being returned against.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', SalesReturn::class);

        $validated = $request->validate([
            'sale' => ['required', 'integer', 'exists:sales,id'],
        ]);

        $sale = Sale::with(['customer:id,name', 'lines.product:id,sku,name,unit_id', 'lines.product.unit:id,code,allows_decimals', 'lines.unit:id,code'])
            ->findOrFail($validated['sale']);

        abort_unless($sale->isPosted(), 404);

        return Inertia::render('SalesReturns/Create', [
            'title' => "Return against {$sale->reference}",
            'sale' => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer' => $sale->customer?->name,
                'sale_date' => $sale->sale_date?->format('d M Y'),
                'subtotal' => $sale->subtotal,
                'discount' => $sale->discount,
                'mobilization' => $sale->mobilization,
                'total_amount' => $sale->total_amount,
                'balance_due' => $sale->balance_due,
                'lines' => $sale->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'returned_quantity' => $line->returned_quantity,
                    'returnable' => $line->returnable(),
                    'unit' => $line->product?->unit?->code,
                    'decimals' => (bool) $line->product?->unit?->allows_decimals,
                    // Returns count in the product's own unit, so a line sold
                    // by the box shows the price of one piece of it: the
                    // line total over the pieces. Only an estimate — the
                    // refund itself is the pieces' share of what was paid.
                    'unit_price' => $line->unit_id === null
                        ? $line->unit_price
                        : number_format(
                            Quantity::centavos($line->line_total) * Quantity::SCALE / max(Quantity::toMilli($line->quantity), 1) / 100,
                            2, '.', ''
                        ),
                    'typed_as' => $line->unit_id === null ? null : UnitConversion::describe(
                        $line->unit_quantity,
                        $line->unit?->code,
                        $line->quantity,
                        $line->product?->unit?->code,
                        $line->unit_factor,
                    ),
                ])->all(),
            ],
            'reasons' => [
                ['value' => 'Damaged on arrival', 'label' => 'Damaged on arrival'],
                ['value' => 'Wrong item', 'label' => 'Wrong item'],
                ['value' => 'Changed their mind', 'label' => 'Changed their mind'],
                ['value' => 'Faulty', 'label' => 'Faulty'],
            ],
            'dates' => [
                'min' => $this->floorDate(),
                'max' => Carbon::today()->toDateString(),
                'today' => Carbon::today()->toDateString(),
            ],
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreSalesReturnRequest $request): RedirectResponse
    {
        $this->authorize('create', SalesReturn::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('sales-returns.show', $existing)
                    ->with('info', "Sales return [{$existing->reference}] was already recorded.");
            }

            throw ValidationException::withMessages([
                'idempotency_key' => "This form already recorded return [{$existing->reference}]. "
                    .'Save again to record what is on screen now as a new return.',
            ]);
        }

        $data = $request->validated();

        try {
            $return = $this->poster->post($data, $data['lines']);
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()->route('sales-returns.show', $existing);
            }

            throw $e;
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales-returns.show', $return)
            ->with('success', "Sales return [{$return->reference}] was posted.");
    }

    public function show(Request $request, SalesReturn $salesReturn): Response
    {
        $this->authorize('view', $salesReturn);

        $salesReturn->auditView();

        return Inertia::render('SalesReturns/Show', array_merge(
            ['title' => $salesReturn->reference],
            $this->detail($request, $salesReturn),
        ));
    }

    /**
     * The same return, as JSON, for the dialog on the list screen and for
     * the reference column on the movement ledger.
     *
     * Both this and `show()` call `detail()`, so the two cannot drift.
     */
    public function preview(Request $request, SalesReturn $salesReturn): JsonResponse
    {
        $this->authorize('view', $salesReturn);

        $salesReturn->auditView();

        return response()->json($this->detail($request, $salesReturn));
    }

    /**
     * Everything this return shows, on the page or in the dialog.
     *
     * @return array<string, mixed>
     */
    protected function detail(Request $request, SalesReturn $salesReturn): array
    {
        $salesReturn->load([
            'sale:id,reference,customer_id,location_id',
            'sale.customer:id,name',
            'sale.location:id,name',
            'lines.product:id,sku,name',
            'postedBy:id,name',
        ]);

        $seesCost = $request->user()->can('viewCost', Sale::class);

        // The comparison view, not a stored figure: see `Sale::vatSplit()`.
        $rate = Vat::currentRate();
        $vat = Vat::split($salesReturn->total_amount, $rate);

        return [
            'salesReturn' => array_merge([
                'id' => $salesReturn->id,
                'reference' => $salesReturn->reference,
                'sale_id' => $salesReturn->sale_id,
                'sale_reference' => $salesReturn->sale?->reference,
                // Back into the batches its sale ate: where it was sold
                // (phase 31.4).
                'location' => $salesReturn->sale?->location?->name,
                'customer' => $salesReturn->sale?->customer?->name,
                'return_date' => $salesReturn->return_date?->format('d M Y'),
                'reason' => $salesReturn->reason,
                'notes' => $salesReturn->notes,
                'total_amount' => $salesReturn->total_amount,
                // Off what the customer still owed, and handed back as cash.
                'credited_amount' => $salesReturn->credited_amount,
                'cash_refund' => $salesReturn->cashRefund(),
                'vat_rate' => Vat::rateLabel($rate),
                'net_amount' => $vat['net'],
                'vat_amount' => $vat['vat'],
                'posted_at' => $salesReturn->posted_at?->format('d M Y, g:i A'),
                'posted_by' => $salesReturn->postedBy?->name,
                'lines' => $salesReturn->lines->map(fn ($line) => array_merge([
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'unit_refund' => $line->unit_refund,
                    'line_refund' => $line->line_refund,
                    'net_total' => Vat::net($line->line_refund, $rate),
                    'vat_total' => Vat::tax($line->line_refund, $rate),
                ], $seesCost ? [
                    'line_cost' => $line->line_cost,
                ] : []))->all(),
            ], $seesCost ? [
                'total_cost' => $salesReturn->total_cost,
            ] : []),
            'can' => [
                'viewCost' => $seesCost,
            ],
        ];
    }

    /* ------------------------------------------------------------- Helpers */

    protected function floorDate(): ?string
    {
        $last = InventoryMovement::max('movement_date');

        return $last ? Carbon::parse($last)->toDateString() : null;
    }

    /**
     * @return array{from:?string,to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return ['from' => $validated['from'] ?? null, 'to' => $validated['to'] ?? null];
    }
}
