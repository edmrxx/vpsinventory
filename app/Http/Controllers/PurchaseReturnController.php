<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryException;
use App\Http\Requests\PurchaseReturn\StorePurchaseReturnRequest;
use App\Models\InventoryMovement;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Services\PurchaseReturnPoster;
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
 * Goods sent back to a supplier.
 *
 * A return always starts from the delivery it undoes, so `create` takes a
 * purchase and shows what is still on the shelf from each of its lines.
 *
 * There is no void, edit or delete, the same as a sales return.
 */
class PurchaseReturnController extends Controller
{
    public function __construct(protected PurchaseReturnPoster $poster) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseReturn::class);

        $filters = $this->validatedFilters($request);

        $query = PurchaseReturn::query()
            ->with('purchase:id,reference,supplier_id', 'purchase.supplier:id,name')
            ->withCount('lines')
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('return_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('return_date', '<=', $to));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'reason', 'purchase.reference'])
            ->sortable(['reference', 'return_date', 'total_cost', 'created_at'])
            ->defaultSort('return_date', 'desc')
            ->extra($filters);

        return Inertia::render('PurchaseReturns/Index', [
            'title' => 'Purchase Returns',
            'returns' => $table->paginate()->through(fn (PurchaseReturn $return) => [
                'id' => $return->id,
                'reference' => $return->reference,
                'purchase_id' => $return->purchase_id,
                'purchase_reference' => $return->purchase?->reference,
                'supplier' => $return->purchase?->supplier?->name,
                'return_date' => $return->return_date?->format('d M Y'),
                'lines_count' => $return->lines_count,
                'reason' => $return->reason,
                'total_cost' => $return->total_cost,
            ]),
            'filters' => $table->state(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', PurchaseReturn::class);

        $validated = $request->validate([
            'purchase' => ['required', 'integer', 'exists:purchases,id'],
        ]);

        $purchase = Purchase::with(['supplier:id,name', 'lines.product:id,sku,name,unit_id', 'lines.product.unit:id,code,allows_decimals', 'lines.unit:id,code', 'lines.layer'])
            ->findOrFail($validated['purchase']);

        abort_unless($purchase->isPosted(), 404);

        return Inertia::render('PurchaseReturns/Create', [
            'title' => "Return against {$purchase->reference}",
            'purchase' => [
                'id' => $purchase->id,
                'reference' => $purchase->reference,
                'supplier' => $purchase->supplier?->name,
                'purchase_date' => $purchase->purchase_date?->format('d M Y'),
                'total_amount' => $purchase->total_amount,
                'lines' => $purchase->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'returned_quantity' => $line->returned_quantity,
                    // Never more than is left of this delivery's own batch:
                    // the rest has been sold and cannot go back.
                    'returnable' => $line->returnable(),
                    'unit' => $line->product?->unit?->code,
                    'decimals' => (bool) $line->product?->unit?->allows_decimals,
                    'unit_cost' => $line->unit_cost,
                    // Bought by the box, returned by the piece: say how it
                    // came in, so 500 is not a surprise.
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
                ['value' => 'Wrong item delivered', 'label' => 'Wrong item delivered'],
                ['value' => 'Over-delivered', 'label' => 'Over-delivered'],
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

    public function store(StorePurchaseReturnRequest $request): RedirectResponse
    {
        $this->authorize('create', PurchaseReturn::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('purchase-returns.show', $existing)
                    ->with('info', "Purchase return [{$existing->reference}] was already recorded.");
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
                return redirect()->route('purchase-returns.show', $existing);
            }

            throw $e;
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('purchase-returns.show', $return)
            ->with('success', "Purchase return [{$return->reference}] was posted.");
    }

    public function show(Request $request, PurchaseReturn $purchaseReturn): Response
    {
        $this->authorize('view', $purchaseReturn);

        $purchaseReturn->auditView();

        return Inertia::render('PurchaseReturns/Show', array_merge(
            ['title' => $purchaseReturn->reference],
            $this->detail($request, $purchaseReturn),
        ));
    }

    /**
     * The same return, as JSON, for the dialog on the list screen and for
     * the reference column on the movement ledger.
     *
     * Both this and `show()` call `detail()`, so the two cannot drift.
     */
    public function preview(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorize('view', $purchaseReturn);

        $purchaseReturn->auditView();

        return response()->json($this->detail($request, $purchaseReturn));
    }

    /**
     * Everything this return shows, on the page or in the dialog.
     *
     * @return array<string, mixed>
     */
    protected function detail(Request $request, PurchaseReturn $purchaseReturn): array
    {
        $purchaseReturn->load([
            'purchase:id,reference,supplier_id,vat_rate,location_id',
            'purchase.location:id,name',
            'purchase.supplier:id,name',
            'lines.product:id,sku,name',
            'postedBy:id,name',
        ]);

        // A return follows the delivery it undoes, not the Tax Settings
        // switch. Goods go back to the supplier who sent them, at what
        // they were invoiced at, so a return showing no VAT beside a
        // purchase that shows it would be nonsense.
        //
        // A purchase return has no price of its own — what the supplier
        // gives back is what the goods cost — so the split is taken off
        // that one value.
        $rate = (float) ($purchaseReturn->purchase?->vat_rate ?? 0);
        $vat = Vat::split($purchaseReturn->total_cost, $rate);

        return [
            'purchaseReturn' => [
                'id' => $purchaseReturn->id,
                'reference' => $purchaseReturn->reference,
                'purchase_id' => $purchaseReturn->purchase_id,
                'purchase_reference' => $purchaseReturn->purchase?->reference,
                // Out of the batch its delivery made: where it was received
                // (phase 31.4).
                'location' => $purchaseReturn->purchase?->location?->name,
                'supplier' => $purchaseReturn->purchase?->supplier?->name,
                'return_date' => $purchaseReturn->return_date?->format('d M Y'),
                'reason' => $purchaseReturn->reason,
                'notes' => $purchaseReturn->notes,
                'total_cost' => $purchaseReturn->total_cost,
                // How it was settled (phase 24): off the unpaid bill first,
                // the rest kept as credit with the supplier. A return from
                // before phase 24 carries neither and was refunded in cash.
                'credited_amount' => $purchaseReturn->credited_amount,
                'supplier_credit_amount' => $purchaseReturn->supplier_credit_amount,
                'vat_rate' => Vat::rateLabel($rate),
                'net_amount' => $vat['net'],
                'vat_amount' => $vat['vat'],
                'posted_at' => $purchaseReturn->posted_at?->format('d M Y, g:i A'),
                'posted_by' => $purchaseReturn->postedBy?->name,
                'lines' => $purchaseReturn->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->unit_cost,
                    'line_cost' => $line->line_cost,
                    'net_total' => Vat::net($line->line_cost, $rate),
                    'vat_total' => Vat::tax($line->line_cost, $rate),
                ])->all(),
            ],
            'can' => [],
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
