<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseRequestStatus;
use App\Exceptions\PurchaseRequestException;
use App\Http\Requests\PurchaseRequest\ApprovePurchaseRequestRequest;
use App\Http\Requests\PurchaseRequest\ClosePurchaseRequestRequest;
use App\Http\Requests\PurchaseRequest\RejectPurchaseRequestRequest;
use App\Http\Requests\PurchaseRequest\StorePurchaseRequestRequest;
use App\Models\Department;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Services\PurchaseRequestDesk;
use App\Support\QuoteAbstract;
use App\Support\StaffOptions;
use App\Support\TableQuery;
use App\Support\UnitConversion;
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
 * Asking to buy, and the answer (phase 21).
 *
 * Shaped like the document modules — no edit, no delete, one `detail()` the
 * page and the dialog both read — but a request is decided rather than
 * posted: approved, rejected, or closed when nobody needs it any more.
 */
class PurchaseRequestController extends Controller
{
    public function __construct(protected PurchaseRequestDesk $desk) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $filters = $this->validatedFilters($request);

        $query = PurchaseRequest::query()
            ->with(['supplier:id,name', 'department:id,name', 'requestedBy:id,name'])
            ->withCount(['lines', 'supplierQuotes'])
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['supplier_id'], fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['department_id'], fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['requested_by'], fn ($q, $id) => $q->where('requested_by', $id))
            ->when($filters['from'], fn ($q, $from) => $q->where('request_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->where('request_date', '<', Carbon::parse($to)->addDay()->toDateString()));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'reason', 'supplier.name', 'department.name'])
            ->sortable(['reference', 'request_date', 'estimated_total', 'status'])
            ->defaultSort('request_date', 'desc')
            ->extra($filters);

        $user = $request->user();

        return Inertia::render('PurchaseRequests/Index', [
            'title' => 'Purchase Requests',
            'requests' => $table->paginate()->through(fn (PurchaseRequest $row) => [
                'id' => $row->id,
                'reference' => $row->reference,
                'request_date' => $row->request_date?->format('d M Y'),
                'needed_by' => $row->needed_by?->format('d M Y'),
                'supplier' => $row->supplier?->name,
                'department' => $row->department?->name,
                'requested_by' => $row->requestedBy?->name,
                'reason' => Str::limit((string) $row->reason, 80),
                'lines_count' => $row->lines_count,
                // How far its canvass has got (phase 24.2).
                'quotes_count' => $row->supplier_quotes_count,
                'awarded' => $row->awarded_at !== null,
                'estimated_total' => $row->estimated_total,
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                'status_tone' => $row->status->tone(),
                // Read off the row: whether this person may answer it.
                'can' => ['decide' => $user->can('approve', $row)],
            ]),
            'filters' => $table->state(),
            'options' => [
                'statuses' => PurchaseRequestStatus::options(),
                'suppliers' => $this->supplierOptions(),
                // Every department, switched-off ones too: old requests name them.
                'departments' => Department::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Department $d) => ['value' => $d->id, 'label' => $d->name])->all(),
                'staff' => StaffOptions::all(),
            ],
            'waiting' => PurchaseRequest::waiting()['count'],
            'can' => [
                'create' => $user->can('create', PurchaseRequest::class),
                'approve' => $user->can('purchase_requests.approve'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', PurchaseRequest::class);

        return Inertia::render('PurchaseRequests/Create', [
            'title' => 'New purchase request',
            'suppliers' => $this->supplierOptions(active: true),
            'departments' => Department::options(),
            'products' => Product::buyingOptions(),
            'today' => Carbon::today()->toDateString(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StorePurchaseRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', PurchaseRequest::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('purchase-requests.show', $existing)
                    ->with('info', "Request [{$existing->reference}] was already raised.");
            }

            throw ValidationException::withMessages([
                'idempotency_key' => "This form already raised request [{$existing->reference}]. "
                    .'Save again to raise what is on screen now as a new request.',
            ]);
        }

        $data = $request->validated();

        try {
            $raised = $this->desk->raise($data, $data['lines']);
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()
                    ->route('purchase-requests.show', $existing)
                    ->with('info', "Request [{$existing->reference}] was already raised.");
            }

            throw $e;
        } catch (PurchaseRequestException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('purchase-requests.show', $raised)
            ->with('success', "Request [{$raised->reference}] was sent for approval.");
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): Response
    {
        $this->authorize('view', $purchaseRequest);

        $purchaseRequest->auditView();

        return Inertia::render('PurchaseRequests/Show', array_merge(
            ['title' => $purchaseRequest->reference],
            $this->detail($request, $purchaseRequest),
        ));
    }

    /**
     * The same request, as JSON, for the dialog on the list screen. It is
     * exactly what `show()` renders, because both call `detail()`.
     */
    public function preview(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorize('view', $purchaseRequest);

        $purchaseRequest->auditView();

        return response()->json($this->detail($request, $purchaseRequest));
    }

    /**
     * Everything a request shows, on the page or in the dialog.
     *
     * @return array{request: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, PurchaseRequest $purchaseRequest): array
    {
        $purchaseRequest->load([
            'supplier:id,name,code',
            'department:id,name',
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.product:id,sku,name,unit_id,on_hand,reorder_level',
            'lines.product.unit:id,code',
            'lines.unit:id,code',
            'requestedBy:id,name',
            'decidedBy:id,name',
            'closedBy:id,name',
            'awardedBy:id,name',
            'purchaseOrders:id,reference,purchase_request_id,status,supplier_id,total_amount',
            'purchaseOrders.supplier:id,name',
        ]);

        $user = $request->user();
        $status = $purchaseRequest->status;

        return [
            'request' => [
                'id' => $purchaseRequest->id,
                'reference' => $purchaseRequest->reference,
                'request_date' => $purchaseRequest->request_date?->format('d M Y'),
                'needed_by' => $purchaseRequest->needed_by?->format('d M Y'),
                'supplier' => $purchaseRequest->supplier?->name,
                'supplier_code' => $purchaseRequest->supplier?->code,
                'department' => $purchaseRequest->department?->name,
                'reason' => $purchaseRequest->reason,
                'notes' => $purchaseRequest->notes,
                'estimated_total' => $purchaseRequest->estimated_total,
                'status' => $status->value,
                'status_label' => $status->label(),
                'status_tone' => $status->tone(),
                'requested_at' => $purchaseRequest->created_at?->format('d M Y, g:i A'),
                'requested_by' => $purchaseRequest->requestedBy?->name,
                'decided_at' => $purchaseRequest->decided_at?->format('d M Y, g:i A'),
                'decided_by' => $purchaseRequest->decidedBy?->name,
                'decision_note' => $purchaseRequest->decision_note,
                'closed_at' => $purchaseRequest->closed_at?->format('d M Y, g:i A'),
                'closed_by' => $purchaseRequest->closedBy?->name,
                'close_reason' => $purchaseRequest->close_reason,
                // The canvass (phase 24.2): every supplier's price side by
                // side, and the award. Shown once the request is approved,
                // or whenever it carries quotes.
                'canvass' => $purchaseRequest->isApproved() || $purchaseRequest->supplierQuotes()->exists()
                    ? QuoteAbstract::of($purchaseRequest) + [
                        'awarded_at' => $purchaseRequest->awarded_at?->format('d M Y, g:i A'),
                        'awarded_by' => $purchaseRequest->awardedBy?->name,
                    ]
                    : null,
                // The orders it became: one by hand (phase 22), or one per
                // winning supplier from its award (phase 24.3).
                'orders' => $purchaseRequest->purchaseOrders->map(fn ($order) => [
                    'id' => $order->id,
                    'reference' => $order->reference,
                    'supplier' => $order->supplier?->name,
                    'total_amount' => $order->total_amount,
                    'status_label' => $order->status->label(),
                ])->all(),
                'lines' => $purchaseRequest->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
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
                    // What is on the shelf now, so whoever decides can see
                    // whether it is really needed.
                    'on_hand' => $line->product?->on_hand,
                    'reorder_level' => $line->product?->reorder_level,
                ])->all(),
            ],
            'can' => [
                'approve' => $user->can('approve', $purchaseRequest),
                'reject' => $user->can('reject', $purchaseRequest),
                'close' => $user->can('close', $purchaseRequest),
                // Approved and not yet ordered: it can become an order. An
                // awarded one is ordered from its award instead (phase 24.3).
                'order' => $purchaseRequest->isApproved()
                    && ! $purchaseRequest->isOrdered()
                    && ! $purchaseRequest->isAwarded()
                    && $user->can('create', PurchaseOrder::class),
                'viewOrders' => $user->can('viewAny', PurchaseOrder::class),
                // A supplier is asked for prices only on what was approved
                // (phase 24.1); before that the request is still a question.
                'printRfq' => $purchaseRequest->isApproved(),
                'quote' => $user->can('quote', $purchaseRequest),
                'award' => $user->can('award', $purchaseRequest),
                'reopenAward' => $user->can('reopenAward', $purchaseRequest),
                'orderFromAward' => $user->can('orderFromAward', $purchaseRequest),
                // Said plainly, so the screen can explain why Approve is not
                // there for the one person who would expect it, or warn
                // that approving is approving their own request.
                'ownRequest' => $purchaseRequest->isPending()
                    && $user->can('purchase_requests.approve')
                    && $purchaseRequest->wasRaisedBy($user),
                'selfApproval' => PurchaseRequest::selfApprovalAllowed(),
            ],
        ];
    }

    public function approve(ApprovePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('approve', $purchaseRequest);

        try {
            $this->desk->approve($purchaseRequest, $request->user(), $request->validated()['note'] ?? null);
        } catch (PurchaseRequestException $e) {
            throw ValidationException::withMessages(['note' => $e->getMessage()]);
        }

        return back()->with('success', "Request [{$purchaseRequest->reference}] was approved.");
    }

    public function reject(RejectPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('reject', $purchaseRequest);

        try {
            $this->desk->reject($purchaseRequest, $request->user(), $request->validated()['reason']);
        } catch (PurchaseRequestException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Request [{$purchaseRequest->reference}] was rejected.");
    }

    public function close(ClosePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('close', $purchaseRequest);

        try {
            $this->desk->close($purchaseRequest, $request->user(), $request->validated()['reason']);
        } catch (PurchaseRequestException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Request [{$purchaseRequest->reference}] was closed.");
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * @return array{status:?string, supplier_id:?int, department_id:?int, requested_by:?int, from:?string, to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', PurchaseRequestStatus::values())],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'requested_by' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'supplier_id' => isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            'department_id' => isset($validated['department_id']) ? (int) $validated['department_id'] : null,
            'requested_by' => isset($validated['requested_by']) ? (int) $validated['requested_by'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
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
            ->get(['id', 'name'])
            ->map(fn (Supplier $s) => ['value' => $s->id, 'label' => $s->name])
            ->all();
    }
}
