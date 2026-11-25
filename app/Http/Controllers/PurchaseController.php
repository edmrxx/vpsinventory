<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Http\Requests\Purchase\StorePurchaseRequest;
use App\Http\Requests\Purchase\VoidPurchaseRequest;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\PurchasePoster;
use App\Support\Quantity;
use App\Support\StaffOptions;
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
 * Goods bought in.
 *
 * The first document module, so it sets the shape sales and returns follow.
 * There is no edit and no delete, on purpose and all the way down: no route,
 * no controller method, no policy ability. A posted purchase is corrected
 * with a void while its stock is untouched, or with a return afterwards.
 */
class PurchaseController extends Controller
{
    public function __construct(protected PurchasePoster $poster) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Purchase::class);

        $filters = $this->validatedFilters($request);

        $query = Purchase::query()
            ->with(['supplier:id,name', 'purchaseOrder:id,reference', 'location:id,name'])
            ->withCount('lines')
            ->when($filters['supplier_id'], fn ($q, $id) => $q->where('supplier_id', $id))
            // Where it was received (phase 31.3).
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('purchase_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('purchase_date', '<=', $to))
            // Who posted it. The dashboard links here with it set.
            ->when($filters['posted_by'], fn ($q, $id) => $q->where('posted_by', $id));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'supplier_reference', 'supplier.name'])
            ->sortable(['reference', 'purchase_date', 'total_amount', 'status', 'created_at'])
            ->defaultSort('purchase_date', 'desc')
            ->extra($filters);

        return Inertia::render('Purchases/Index', [
            'title' => 'Purchases',
            'purchases' => $table->paginate()->through(fn (Purchase $purchase) => [
                'id' => $purchase->id,
                'reference' => $purchase->reference,
                'supplier_reference' => $purchase->supplier_reference,
                'supplier' => $purchase->supplier?->name,
                'order' => $purchase->purchaseOrder?->reference,
                'location' => $purchase->location?->name,
                'purchase_date' => $purchase->purchase_date?->format('d M Y'),
                'lines_count' => $purchase->lines_count,
                'total_amount' => $purchase->total_amount,
                // What is still owed on it (phase 24), 0 once paid.
                'balance_due' => $purchase->balance_due,
                'status' => $purchase->status->value,
                'status_label' => $purchase->status->label(),
                'status_tone' => $purchase->status->tone(),
                'can' => [
                    // Whether the stock is still untouched is not asked here:
                    // that is a query per row. The detail screen and the
                    // ledger both check it before anything happens.
                    'void' => $request->user()->can('void', $purchase),
                ],
            ]),
            'filters' => $table->state(),
            'options' => [
                'suppliers' => $this->supplierOptions(),
                'locations' => Location::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name])
                    ->all(),
                'staff' => StaffOptions::all(),
                'statuses' => [
                    ['value' => DocumentStatus::Posted->value, 'label' => 'Posted'],
                    ['value' => DocumentStatus::Voided->value, 'label' => 'Voided'],
                ],
            ],
            'can' => ['create' => $request->user()->can('create', Purchase::class)],
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $this->authorize('create', Purchase::class);

        $fromOrder = null;

        if ($request->filled('order')) {
            $order = PurchaseOrder::with([
                'supplier:id,name',
                'deliverTo:id,is_active',
                'lines' => fn ($q) => $q->orderBy('id'),
                'lines.product:id,sku,name,unit_id',
                'lines.product.unit:id,code',
                'lines.unit:id,code',
            ])->find($request->integer('order'));

            if (! $order || ! $order->isOutstanding()) {
                return redirect()
                    ->route('purchases.create')
                    ->with('error', match (true) {
                        $order === null => 'That order was not found.',
                        $order->isPendingApproval() => "Order [{$order->reference}] is waiting for approval, so nothing can be received against it yet.",
                        default => "Order [{$order->reference}] is ".strtolower($order->status->label()).', so nothing more can be received against it.',
                    });
            }

            $fromOrder = $this->fromOrder($order);
        }

        return Inertia::render('Purchases/Create', [
            'title' => 'New purchase',
            'fromOrder' => $fromOrder,
            'suppliers' => $this->supplierOptions(active: true),
            'products' => Product::buyingOptions(),
            // Where the goods may go on the shelf (phase 31.3), and the one
            // the form starts on: the order's Deliver to, else the default.
            'locations' => Location::options(),
            'defaultLocationId' => Location::default()?->id,
            // The form may not be dated before the last posted document, nor
            // in the future. The inputs carry both as their own limits.
            'dates' => [
                'min' => InventoryMovement::max('movement_date')
                    ? Carbon::parse(InventoryMovement::max('movement_date'))->toDateString()
                    : null,
                'max' => Carbon::today()->toDateString(),
                'today' => Carbon::today()->toDateString(),
            ],
            // Sent back with the form, so a double-clicked Save lands on the
            // purchase it already made rather than making a second one.
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StorePurchaseRequest $request): RedirectResponse
    {
        $this->authorize('create', Purchase::class);

        if ($existing = $request->alreadySaved()) {
            // The very same form, sent twice: a double click, a refresh, a
            // flaky connection. Land on the purchase it already made.
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('purchases.show', $existing)
                    ->with('info', "Purchase [{$existing->reference}] was already recorded.");
            }

            // A different delivery, typed into a create screen the browser
            // brought back out of its history with the old key still on it.
            // Posting it would be right; landing on the old purchase would
            // silently throw it away. So neither: say so and reload, which
            // hands the form a fresh key to save with.
            throw ValidationException::withMessages([
                'idempotency_key' => "This form already recorded purchase [{$existing->reference}]. "
                    .'Save again to record what is on screen now as a new purchase.',
            ]);
        }

        $data = $request->validated();

        try {
            $purchase = $this->poster->post($data, $data['lines']);
        } catch (UniqueConstraintViolationException $e) {
            // The same form was sent twice. Land on the purchase it made.
            if ($existing = $this->alreadySaved($data)) {
                return redirect()
                    ->route('purchases.show', $existing)
                    ->with('info', "Purchase [{$existing->reference}] was already recorded.");
            }

            throw $e;
        } catch (InventoryException $e) {
            // The ledger refuses in words the user can read, so they belong
            // under the field they are about rather than in a 500 page.
            throw ValidationException::withMessages([
                str_contains($e->getMessage(), 'dated') ? 'purchase_date' : 'lines' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('purchases.show', $purchase)
            ->with('success', "Purchase [{$purchase->reference}] was posted.");
    }

    /**
     * The delivery form, filled from what is still to come on an order.
     *
     * Each line carries its order line, and what is left of it: in the unit
     * it was ordered in when that comes out in whole boxes (2 boxes), otherwise in the
     * product's own unit (18 pcs) at the agreed piece cost.
     *
     * @return array<string, mixed>
     */
    protected function fromOrder(PurchaseOrder $order): array
    {
        $lines = [];
        $summary = [];

        foreach ($order->lines as $line) {
            $left = $line->outstandingMilli();
            $own = $line->product?->unit?->code;

            $summary[] = [
                'name' => $line->product?->name,
                'sku' => $line->product?->sku,
                'ordered' => $line->quantity,
                'received' => $line->received_quantity,
                'outstanding' => Quantity::fromMilli($left),
                'unit' => $own,
            ];

            if ($left === 0) {
                continue;
            }

            $factorMilli = Quantity::toMilli($line->unit_factor ?: 1);
            // Whole boxes only: 1.5 boxes would be refused where boxes are counted whole.
            $inOrderUnit = $line->unit_id !== null && $factorMilli > 0 && $left % $factorMilli === 0;

            $lines[] = [
                'purchase_order_line_id' => $line->id,
                'product_id' => $line->product_id,
                'unit_id' => $inOrderUnit ? $line->unit_id : '',
                'quantity' => $inOrderUnit
                    ? intdiv($left, $factorMilli)
                    : Quantity::fromMilli($left),
                'unit_cost' => $inOrderUnit
                    ? number_format((float) $line->unit_cost, 2, '.', '')
                    : number_format($line->agreedPieceCost(), 2, '.', ''),
            ];
        }

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'supplier_id' => $order->supplier_id,
            'supplier' => $order->supplier?->name,
            // Where the order asked for it (phase 31.3), while that place is
            // still in use; otherwise the form starts on the default.
            'deliver_to_location_id' => $order->deliverTo?->is_active ? $order->deliver_to_location_id : null,
            'lines' => $lines,
            'summary' => $summary,
        ];
    }

    public function show(Request $request, Purchase $purchase): Response
    {
        $this->authorize('view', $purchase);

        $purchase->auditView();

        return Inertia::render('Purchases/Show', array_merge(
            ['title' => $purchase->reference],
            $this->detail($request, $purchase),
        ));
    }

    /**
     * The same document, as JSON, for the dialog on the list screen.
     *
     * It answers with exactly what `show()` renders, because both call
     * `detail()`. Two copies of this payload would drift the first time a
     * column was added to one of them.
     *
     * The view is written to the trail here as well. Reading a document is
     * reading it, whether it opened in a dialog or on a page of its own.
     */
    public function preview(Request $request, Purchase $purchase): JsonResponse
    {
        $this->authorize('view', $purchase);

        $purchase->auditView();

        return response()->json($this->detail($request, $purchase));
    }

    /**
     * Everything a purchase shows, on the page or in the dialog.
     *
     * @return array{purchase: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, Purchase $purchase): array
    {
        $purchase->load([
            'supplier:id,name,code',
            'lines.product:id,sku,name,unit_id',
            'lines.product.unit:id,code',
            'lines.unit:id,code',
            'lines.layer',
            'lines.orderLine',
            'purchaseOrder:id,reference',
            'location:id,name',
            'allocations' => fn ($q) => $q->orderBy('id'),
            'allocations.payment:id,reference,payment_date,status',
            'postedBy:id,name',
            'voidedBy:id,name',
        ]);

        $voidable = $request->user()->can('void', $purchase) && $purchase->stockIsUntouched();

        // The rate this delivery was invoiced at, read off the row. The
        // buying side does not follow the Tax Settings switch: that one
        // says whether *we* charge VAT, and this says whether the supplier
        // did. The supplier's price is taken as VAT-inclusive, because
        // that is what the invoice says.
        $rate = (float) $purchase->vat_rate;
        $vat = $purchase->vatSplit();

        return [
            'purchase' => [
                'id' => $purchase->id,
                'reference' => $purchase->reference,
                'supplier_reference' => $purchase->supplier_reference,
                'supplier' => $purchase->supplier?->name,
                'supplier_code' => $purchase->supplier?->code,
                'supplier_id' => $purchase->supplier_id,
                // Where the goods went on the shelf (phase 31.3).
                'location' => $purchase->location?->name,
                // The bill (phase 24): what went on the supplier's account,
                // what is still owed, when, and the payments against it.
                'on_account' => $purchase->on_account,
                'balance_due' => $purchase->balance_due,
                'due_date' => $purchase->due_date?->format('d M Y'),
                'overdue' => $purchase->isPosted() && (float) $purchase->balance_due > 0
                    && $purchase->due_date !== null && $purchase->due_date->lt(Carbon::today()),
                'payments' => $purchase->allocations->map(fn ($allocation) => [
                    'id' => $allocation->payment?->id,
                    'reference' => $allocation->payment?->reference,
                    'payment_date' => $allocation->payment?->payment_date?->format('d M Y'),
                    'amount' => $allocation->amount,
                    'status' => $allocation->payment?->status->value,
                    'status_label' => $allocation->payment?->status->label(),
                ])->all(),
                // The order it was received against (phase 23), if any.
                'order' => $purchase->purchaseOrder ? [
                    'id' => $purchase->purchaseOrder->id,
                    'reference' => $purchase->purchaseOrder->reference,
                ] : null,
                'purchase_date' => $purchase->purchase_date?->format('d M Y'),
                'status' => $purchase->status->value,
                'status_label' => $purchase->status->label(),
                'status_tone' => $purchase->status->tone(),
                'total_amount' => $purchase->total_amount,
                'vat_rate' => $purchase->vat_rate,
                'net_amount' => $vat['net'],
                'vat_amount' => $vat['vat'],
                'notes' => $purchase->notes,
                'posted_at' => $purchase->posted_at?->format('d M Y, g:i A'),
                'posted_by' => $purchase->postedBy?->name,
                'voided_at' => $purchase->voided_at?->format('d M Y, g:i A'),
                'voided_by' => $purchase->voidedBy?->name,
                'void_reason' => $purchase->void_reason,
                'lines' => $purchase->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'unit' => $line->product?->unit?->code,
                    // As typed: 5 box at 400. A line bought in the
                    // product's own unit reads the same both ways.
                    'converted' => $line->unit_id !== null,
                    'unit_quantity' => $line->unit_quantity,
                    'unit_code' => $line->unit?->code ?? $line->product?->unit?->code,
                    'unit_price' => $line->unit_price,
                    'quantity_label' => UnitConversion::describe(
                        $line->unit_quantity,
                        $line->unit?->code,
                        $line->quantity,
                        $line->product?->unit?->code,
                        $line->unit_factor,
                    ),
                    // What one piece cost, which is what the batch holds.
                    'unit_cost' => $line->unit_cost,
                    // Against an order: whether this line was on it, and
                    // the agreed piece cost when the invoice differs.
                    'on_order' => $line->purchase_order_line_id !== null,
                    'agreed_piece_cost' => $line->orderLine
                        && Quantity::centavos((float) $line->unit_cost) !== Quantity::centavos($line->orderLine->agreedPieceCost())
                        ? number_format($line->orderLine->agreedPieceCost(), 2, '.', '')
                        : null,
                    'line_total' => $line->line_total,
                    'net_total' => Vat::net($line->line_total, $rate),
                    'vat_total' => Vat::tax($line->line_total, $rate),
                ])->all(),
            ],
            'can' => [
                'void' => $voidable,
                'return' => $purchase->isPosted()
                    && $request->user()->can('create', PurchaseReturn::class)
                    && $purchase->lines->sum(fn ($line) => $line->returnable()) > 0,
                // Said plainly, so the screen can explain why the button is
                // not there rather than simply hiding it.
                'voidBlockedByStock' => $purchase->isPosted() && ! $purchase->stockIsUntouched(),
                'viewOrder' => $request->user()->can('viewAny', PurchaseOrder::class),
                'pay' => $purchase->isPosted() && (float) $purchase->balance_due > 0
                    && $request->user()->can('create', SupplierPayment::class),
                'viewPayments' => $request->user()->can('viewAny', SupplierPayment::class),
            ],
        ];
    }

    public function void(VoidPurchaseRequest $request, Purchase $purchase): RedirectResponse
    {
        $this->authorize('void', $purchase);

        try {
            $this->poster->void($purchase, $request->validated()['reason']);
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Purchase [{$purchase->reference}] was voided.");
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * The purchase this exact form already made, found by the key it carried.
     */
    protected function alreadySaved(array $data): ?Purchase
    {
        if (empty($data['idempotency_key'])) {
            return null;
        }

        return Purchase::firstWhere('idempotency_key', $data['idempotency_key']);
    }

    /**
     * @return array{supplier_id:?int,location_id:?int,status:?string,from:?string,to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => ['nullable', 'in:'.implode(',', DocumentStatus::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'posted_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return [
            'supplier_id' => isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            'location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'posted_by' => isset($validated['posted_by']) ? (int) $validated['posted_by'] : null,
        ];
    }

    /**
     * @return list<array{value:int,label:string}>
     */
    protected function supplierOptions(bool $active = false): array
    {
        return Supplier::query()
            ->when($active, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_vat_registered', 'payment_terms_days'])
            ->map(fn (Supplier $s) => [
                'value' => $s->id,
                'label' => $s->name,
                'is_vat_registered' => $s->is_vat_registered,
                // The form says when a bill on account would fall due.
                'payment_terms_days' => (int) $s->payment_terms_days,
            ])
            ->all();
    }
}
