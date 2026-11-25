<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PurchaseOrderException;
use App\Http\Requests\PurchaseOrder\CancelPurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\ClosePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\RejectPurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Models\Location;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Services\PurchaseOrderDesk;
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
 * Orders placed with suppliers (phase 22).
 *
 * Shaped like the document modules — no edit, no delete, one `detail()` the
 * page and the dialog both read — but an order moves no stock: it is placed,
 * approved or rejected (phase 24.4), and cancelled if it is called off. The
 * order's own page prints as the order sent to the supplier — and, until it
 * is approved, only as a draft.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(protected PurchaseOrderDesk $desk) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $filters = $this->validatedFilters($request);

        $query = PurchaseOrder::query()
            ->with(['supplier:id,name', 'orderedBy:id,name', 'purchaseRequest:id,reference'])
            ->withCount('lines')
            // "Outstanding" is open or partly received: still waiting for goods.
            ->when($filters['status'] === 'outstanding', fn ($q) => $q->outstanding())
            ->when($filters['status'] && $filters['status'] !== 'outstanding', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['supplier_id'], fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['ordered_by'], fn ($q, $id) => $q->where('ordered_by', $id))
            ->when($filters['from'], fn ($q, $from) => $q->where('order_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->where('order_date', '<', Carbon::parse($to)->addDay()->toDateString()));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'supplier.name'])
            ->sortable(['reference', 'order_date', 'expected_date', 'total_amount', 'status'])
            ->defaultSort('order_date', 'desc')
            ->extra($filters);

        $today = Carbon::today();

        return Inertia::render('PurchaseOrders/Index', [
            'title' => 'Purchase Orders',
            'orders' => $table->paginate()->through(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'order_date' => $order->order_date?->format('d M Y'),
                'expected_date' => $order->expected_date?->format('d M Y'),
                // Open and past the day it was expected.
                'late' => $order->isLate($today),
                'supplier' => $order->supplier?->name,
                'request' => $order->purchaseRequest?->reference,
                'ordered_by' => $order->orderedBy?->name,
                'lines_count' => $order->lines_count,
                'total_amount' => $order->total_amount,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'status_tone' => $order->status->tone(),
            ]),
            'filters' => $table->state(),
            'options' => [
                'statuses' => array_merge(
                    [['value' => 'outstanding', 'label' => 'Outstanding (open or partly received)']],
                    PurchaseOrderStatus::options(),
                ),
                'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Supplier $s) => ['value' => $s->id, 'label' => $s->name])->all(),
                'staff' => StaffOptions::all(),
            ],
            'outstanding' => PurchaseOrder::query()->outstanding()->count(),
            'awaitingApproval' => PurchaseOrder::query()->where('status', PurchaseOrderStatus::PendingApproval->value)->count(),
            'can' => ['create' => $request->user()->can('create', PurchaseOrder::class)],
        ]);
    }

    /**
     * The form. `?request=` fills it from an approved request: its preferred
     * supplier and its lines, at the estimated cost, all of which may be
     * changed to what was agreed with the supplier.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        $from = null;

        if ($request->filled('request')) {
            $source = PurchaseRequest::with(['lines' => fn ($q) => $q->orderBy('id'), 'requestedBy:id,name', 'purchaseOrders:id,reference,purchase_request_id'])
                ->find($request->integer('request'));

            if (! $source || ! $source->isApproved() || $source->isOrdered() || $source->isAwarded()) {
                return redirect()
                    ->route('purchase-orders.create')
                    ->with('error', match (true) {
                        (bool) $source?->isOrdered() => "Request [{$source->reference}] was already ordered on [{$source->purchaseOrders->pluck('reference')->implode('], [')}].",
                        (bool) $source?->isAwarded() => "Request [{$source->reference}] has been awarded to its suppliers, so its orders are made from the award.",
                        default => 'Only an approved request can become an order.',
                    });
            }

            $from = [
                'id' => $source->id,
                'reference' => $source->reference,
                'reason' => $source->reason,
                'requested_by' => $source->requestedBy?->name,
                'supplier_id' => $source->supplier_id,
                'expected_date' => $source->needed_by?->isFuture() || $source->needed_by?->isToday()
                    ? $source->needed_by->toDateString()
                    : null,
                'notes' => $source->notes,
                'lines' => $source->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id ?? '',
                    'quantity' => $line->unit_quantity,
                    'unit_cost' => number_format((float) $line->unit_cost, 2, '.', ''),
                ])->all(),
            ];
        }

        return Inertia::render('PurchaseOrders/Create', [
            'title' => 'New purchase order',
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'is_vat_registered'])
                ->map(fn (Supplier $s) => ['value' => $s->id, 'label' => $s->name, 'is_vat_registered' => $s->is_vat_registered])
                ->all(),
            'products' => Product::buyingOptions(),
            // Where the supplier is to deliver (phase 31.3): the active
            // locations, the form starting on the default.
            'locations' => Location::options(),
            'defaultLocationId' => Location::default()?->id,
            'fromRequest' => $from,
            'today' => Carbon::today()->toDateString(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('purchase-orders.show', $existing)
                    ->with('info', "Order [{$existing->reference}] was already placed.");
            }

            throw ValidationException::withMessages([
                'idempotency_key' => "This form already placed order [{$existing->reference}]. "
                    .'Save again to place what is on screen now as a new order.',
            ]);
        }

        $data = $request->validated();

        try {
            $order = $this->desk->place($data, $data['lines'], $request->user());
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()
                    ->route('purchase-orders.show', $existing)
                    ->with('info', "Order [{$existing->reference}] was already placed.");
            }

            // The request was ordered by somebody else a moment ago.
            if (! empty($data['purchase_request_id'])) {
                throw ValidationException::withMessages([
                    'purchase_request_id' => 'This request was ordered by somebody else a moment ago.',
                ]);
            }

            throw $e;
        } catch (PurchaseOrderException $e) {
            throw ValidationException::withMessages([
                str_contains($e->getMessage(), 'Request [') ? 'purchase_request_id' : 'lines' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', $order->isPendingApproval()
                ? "Order [{$order->reference}] was placed. It waits for approval before it is sent."
                : "Order [{$order->reference}] was placed.");
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->auditView();

        return Inertia::render('PurchaseOrders/Show', array_merge(
            ['title' => $purchaseOrder->reference],
            $this->detail($request, $purchaseOrder),
        ));
    }

    /** The same order, as JSON, for the dialog on the list screen. */
    public function preview(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        $purchaseOrder->auditView();

        return response()->json($this->detail($request, $purchaseOrder));
    }

    /**
     * Everything an order shows, on the page, in the dialog and on paper.
     *
     * @return array{order: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, PurchaseOrder $order): array
    {
        $order->load([
            'supplier:id,name,code,contact_person,phone,email,address,tin',
            'deliverTo:id,name,address',
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.product:id,sku,name,unit_id',
            'lines.product.unit:id,code',
            'lines.unit:id,code',
            'purchaseRequest:id,reference,reason,requested_by,decided_by',
            'purchaseRequest.requestedBy:id,name',
            'purchaseRequest.decidedBy:id,name',
            'orderedBy:id,name',
            'cancelledBy:id,name',
            'closedBy:id,name',
            'approvedBy:id,name',
            'rejectedBy:id,name',
            // The deliveries posted against it, and their lines, for what
            // came in, at what cost, and what came that was not ordered.
            'purchases' => fn ($q) => $q->orderBy('id'),
            'purchases.location:id,name',
            'purchases.lines' => fn ($q) => $q->orderBy('id'),
            'purchases.lines.product:id,sku,name,unit_id',
            'purchases.lines.product.unit:id,code',
        ]);

        // Only deliveries that still stand count; a voided one gave its
        // goods back to the order.
        $posted = $order->purchases->filter(fn ($purchase) => $purchase->isPosted());
        $delivered = $posted->flatMap(fn ($purchase) => $purchase->lines->map(fn ($line) => [$purchase, $line]));

        $user = $request->user();
        $rate = (float) $order->vat_rate;
        $vat = $order->vatSplit();

        return [
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'order_date' => $order->order_date?->format('d M Y'),
                'expected_date' => $order->expected_date?->format('d M Y'),
                'late' => $order->isLate(),
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'status_tone' => $order->status->tone(),
                'supplier' => $order->supplier ? [
                    'id' => $order->supplier->id,
                    'name' => $order->supplier->name,
                    'code' => $order->supplier->code,
                    'contact_person' => $order->supplier->contact_person,
                    'phone' => $order->supplier->phone,
                    'email' => $order->supplier->email,
                    'address' => $order->supplier->address,
                    'tin' => $order->supplier->tin,
                ] : null,
                // Where the supplier is to deliver (phase 31.3), with the
                // address for the paper. Null on an order from before.
                'deliver_to' => $order->deliverTo ? [
                    'id' => $order->deliverTo->id,
                    'name' => $order->deliverTo->name,
                    'address' => $order->deliverTo->address,
                ] : null,
                // Where its goods really arrived: the places of the
                // deliveries that still stand, read off the deliveries.
                // Shown on screen only; the paper keeps Deliver to.
                'received_at' => $posted
                    ->map(fn ($purchase) => $purchase->location?->name)
                    ->filter()->unique()->values()->all(),
                'request' => $order->purchaseRequest ? [
                    'id' => $order->purchaseRequest->id,
                    'reference' => $order->purchaseRequest->reference,
                    'reason' => $order->purchaseRequest->reason,
                    'requested_by' => $order->purchaseRequest->requestedBy?->name,
                    'approved_by' => $order->purchaseRequest->decidedBy?->name,
                ] : null,
                // Not approved yet, or never: the paper says DRAFT — NOT
                // APPROVED, and the "Approved by" line stays blank. Read off
                // the status, never off `approved_at`.
                'draft' => $order->isUnapproved(),
                'approved_at' => $order->approved_at?->format('d M Y, g:i A'),
                // Printed on the order's "Approved by" line. An order from
                // before phase 24.4 carries its request's approver here.
                'approved_by' => $order->isUnapproved() ? null : $order->approvedBy?->name,
                'rejected_at' => $order->rejected_at?->format('d M Y, g:i A'),
                'rejected_by' => $order->rejectedBy?->name,
                'reject_reason' => $order->reject_reason,
                'total_amount' => $order->total_amount,
                'vat_rate' => $order->vat_rate,
                'net_amount' => $vat['net'],
                'vat_amount' => $vat['vat'],
                'notes' => $order->notes,
                'ordered_at' => $order->created_at?->format('d M Y, g:i A'),
                'ordered_by' => $order->orderedBy?->name,
                'cancelled_at' => $order->cancelled_at?->format('d M Y, g:i A'),
                'cancelled_by' => $order->cancelledBy?->name,
                'cancel_reason' => $order->cancel_reason,
                'closed_at' => $order->closed_at?->format('d M Y, g:i A'),
                'closed_by' => $order->closedBy?->name,
                'close_reason' => $order->close_reason,
                'deliveries' => $order->purchases->map(fn ($purchase) => [
                    'id' => $purchase->id,
                    'reference' => $purchase->reference,
                    'supplier_reference' => $purchase->supplier_reference,
                    'purchase_date' => $purchase->purchase_date?->format('d M Y'),
                    'location' => $purchase->location?->name,
                    'total_amount' => $purchase->total_amount,
                    'status' => $purchase->status->value,
                    'status_label' => $purchase->status->label(),
                    'status_tone' => $purchase->status->tone(),
                ])->values()->all(),
                // Goods a delivery brought that the order did not ask for.
                'unordered' => $delivered
                    ->filter(fn ($pair) => $pair[1]->purchase_order_line_id === null)
                    ->map(fn ($pair) => [
                        'purchase' => $pair[0]->reference,
                        'sku' => $pair[1]->product?->sku,
                        'name' => $pair[1]->product?->name,
                        'quantity' => $pair[1]->quantity,
                        'unit' => $pair[1]->product?->unit?->code,
                    ])->values()->all(),
                'lines' => $order->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'unit' => $line->product?->unit?->code,
                    'unit_quantity' => $line->unit_quantity,
                    'unit_code' => $line->unit?->code ?? $line->product?->unit?->code,
                    'quantity_label' => UnitConversion::describe(
                        $line->unit_quantity,
                        $line->unit?->code,
                        $line->quantity,
                        $line->product?->unit?->code,
                        $line->unit_factor,
                    ),
                    'unit_cost' => $line->unit_cost,
                    'line_total' => $line->line_total,
                    'net_total' => Vat::net($line->line_total, $rate),
                    'vat_total' => Vat::tax($line->line_total, $rate),
                    'received' => $line->received_quantity,
                    'outstanding' => Quantity::fromMilli($line->outstandingMilli()),
                    'agreed_piece_cost' => number_format($line->agreedPieceCost(), 2, '.', ''),
                    // What each delivery that filled this line was invoiced
                    // at, per piece, where it differs from what was agreed.
                    'cost_changes' => $delivered
                        ->filter(fn ($pair) => (int) $pair[1]->purchase_order_line_id === $line->id
                            && Quantity::centavos((float) $pair[1]->unit_cost) !== Quantity::centavos($line->agreedPieceCost()))
                        ->map(fn ($pair) => [
                            'purchase' => $pair[0]->reference,
                            'piece_cost' => $pair[1]->unit_cost,
                        ])->values()->all(),
                ])->all(),
            ],
            'can' => [
                'approve' => $user->can('approve', $order),
                'reject' => $user->can('reject', $order),
                // The approver says no with Reject; Cancel is for somebody
                // else who may call it off while it waits.
                'cancel' => $user->can('cancel', $order) && ! ($order->isPendingApproval() && $user->can('reject', $order)),
                // Placed it, may approve orders, but not their own: the
                // screen says why Approve is not there.
                'ownOrder' => $order->isPendingApproval()
                    && $order->wasPlacedBy($user)
                    && $user->can('purchase_orders.approve')
                    && ! PurchaseRequest::selfApprovalAllowed(),
                'close' => $user->can('close', $order),
                'receive' => $order->isOutstanding() && $user->can('create', Purchase::class),
                'viewPurchases' => $user->can('viewAny', Purchase::class),
                'viewRequest' => $user->can('viewAny', PurchaseRequest::class),
            ],
        ];
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('approve', $purchaseOrder);

        try {
            $this->desk->approve($purchaseOrder, $request->user());
        } catch (PurchaseOrderException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return back()->with('success', "Order [{$purchaseOrder->reference}] was approved. It may now be sent to the supplier.");
    }

    public function reject(RejectPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('reject', $purchaseOrder);

        try {
            $this->desk->reject($purchaseOrder, $request->user(), $request->validated()['reason']);
        } catch (PurchaseOrderException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Order [{$purchaseOrder->reference}] was rejected.");
    }

    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('cancel', $purchaseOrder);

        try {
            $this->desk->cancel($purchaseOrder, $request->user(), $request->validated()['reason']);
        } catch (PurchaseOrderException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Order [{$purchaseOrder->reference}] was cancelled.");
    }

    /** Close a partly received order short: the rest is not coming. */
    public function close(ClosePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('close', $purchaseOrder);

        try {
            $this->desk->closeShort($purchaseOrder, $request->user(), $request->validated()['reason']);
        } catch (PurchaseOrderException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Order [{$purchaseOrder->reference}] was closed short.");
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * @return array{status:?string, supplier_id:?int, ordered_by:?int, from:?string, to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:outstanding,'.implode(',', PurchaseOrderStatus::values())],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'ordered_by' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'supplier_id' => isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            'ordered_by' => isset($validated['ordered_by']) ? (int) $validated['ordered_by'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }
}
