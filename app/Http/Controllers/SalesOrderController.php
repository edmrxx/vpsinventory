<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Exceptions\QuotationException;
use App\Exceptions\SalesOrderException;
use App\Http\Requests\SalesOrder\ApproveSalesOrderRequest;
use App\Http\Requests\SalesOrder\CancelSalesOrderRequest;
use App\Http\Requests\SalesOrder\StoreSalesOrderRequest;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Services\SalesOrderDesk;
use App\Support\Quantity;
use App\Support\Reservations;
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
 * Orders customers place, holding stock for them (phase 29).
 *
 * Shaped like the document modules — no edit, no delete, one `detail()` the
 * page and the dialog both read. An order is taken pending, approved (its
 * stock reserved until a date), turned into a sale on the sale form
 * (`/sales/create?order=`), or cancelled, which releases the stock.
 */
class SalesOrderController extends Controller
{
    public function __construct(protected SalesOrderDesk $desk) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SalesOrder::class);

        $filters = $this->validatedFilters($request);
        $today = Carbon::today();

        $query = SalesOrder::query()
            ->with(['customer:id,name', 'createdBy:id,name', 'sale:id,reference'])
            ->withCount('lines')
            ->when($filters['status'] === 'reserving', fn ($q) => $q->reserving($today))
            ->when($filters['status'] === 'expired', fn ($q) => $q->lapsed($today))
            ->when(in_array($filters['status'], [SalesOrderStatus::Pending->value, SalesOrderStatus::Fulfilled->value, SalesOrderStatus::Cancelled->value], true),
                fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['customer_id'], fn ($q, $id) => $q->where('customer_id', $id))
            ->when($filters['created_by'], fn ($q, $id) => $q->where('created_by', $id))
            ->when($filters['from'], fn ($q, $from) => $q->where('order_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->where('order_date', '<', Carbon::parse($to)->addDay()->toDateString()));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'attention', 'customer.name'])
            ->sortable(['reference', 'order_date', 'reserve_until', 'total_amount', 'status'])
            ->defaultSort('order_date', 'desc')
            ->extra($filters);

        $user = $request->user();

        return Inertia::render('SalesOrders/Index', [
            'title' => 'Sales Orders',
            'orders' => $table->paginate()->through(function (SalesOrder $order) use ($today, $user) {
                $standing = $order->standing($today);

                return [
                    'id' => $order->id,
                    'reference' => $order->reference,
                    'order_date' => $order->order_date?->format('d M Y'),
                    'reserve_until' => $order->reserve_until?->format('d M Y'),
                    'customer' => $order->customer?->name,
                    'attention' => $order->attention,
                    'created_by' => $order->createdBy?->name,
                    'sale' => $order->sale?->reference,
                    'lines_count' => $order->lines_count,
                    'total_amount' => $order->total_amount,
                    'status' => $standing['value'],
                    'status_label' => $standing['label'],
                    'status_tone' => $standing['tone'],
                    'can' => ['approve' => $user->can('approve', $order)],
                ];
            }),
            'filters' => $table->state(),
            'options' => [
                'statuses' => [
                    ['value' => SalesOrderStatus::Pending->value, 'label' => 'Pending approval'],
                    ['value' => 'reserving', 'label' => 'Reserved, still holding'],
                    ['value' => 'expired', 'label' => 'Expired'],
                    ['value' => SalesOrderStatus::Fulfilled->value, 'label' => 'Fulfilled (became a sale)'],
                    ['value' => SalesOrderStatus::Cancelled->value, 'label' => 'Cancelled or released'],
                ],
                'customers' => Customer::options(),
                'staff' => StaffOptions::all(),
            ],
            'pending' => SalesOrder::query()->where('status', SalesOrderStatus::Pending->value)->count(),
            'can' => ['create' => $user->can('create', SalesOrder::class)],
        ]);
    }

    /** The form. `?quotation=` fills it from a live quotation. */
    public function create(Request $request): Response|RedirectResponse
    {
        $this->authorize('create', SalesOrder::class);

        $user = $request->user();
        $withCost = $user->can('viewCost', Sale::class);
        $today = Carbon::today();
        $from = null;

        if ($request->filled('quotation')) {
            $quotation = Quotation::with(['lines' => fn ($q) => $q->orderBy('id')])->find($request->integer('quotation'));

            if (! $quotation || ! $quotation->isLive()) {
                return redirect()
                    ->route('sales-orders.create')
                    ->with('error', $quotation
                        ? "Quotation [{$quotation->reference}] is ".strtolower($quotation->standing()['label']).', so it cannot become an order.'
                        : 'That quotation was not found.');
            }

            $from = [
                'id' => $quotation->id,
                'reference' => $quotation->reference,
                'customer_id' => $quotation->customer_id,
                'attention' => $quotation->attention,
                'discount' => number_format((float) $quotation->discount, 2, '.', ''),
                'notes' => $quotation->notes,
                'lines' => $quotation->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id ?? '',
                    'quantity' => $line->unit_quantity,
                    'unit_price' => number_format((float) $line->unit_price, 2, '.', ''),
                ])->all(),
            ];
        }

        // Where the order will hold its stock (phase 31.4): the one picked,
        // else the default. The products' figures are that place's, and the
        // form reloads them when it changes.
        $location = Location::startingAt($request->integer('location') ?: null);

        return Inertia::render('SalesOrders/Create', [
            'title' => 'New sales order',
            'customers' => Customer::options(activeOnly: true),
            'locations' => Location::options(),
            'locationId' => $location?->id,
            'products' => Product::sellingOptions($withCost, null, $location?->id),
            'customerId' => $from['customer_id'] ?? ($request->integer('customer') ?: null),
            'fromQuotation' => $from,
            'today' => $today->toDateString(),
            'reserveUntil' => $today->copy()->addDays(max(1, (int) settings('documents.sales_order_reserve_days')))->toDateString(),
            'idempotencyKey' => (string) Str::uuid(),
            'can' => ['viewCost' => $withCost, 'approve' => $user->can('sales_orders.approve')],
        ]);
    }

    public function store(StoreSalesOrderRequest $request): RedirectResponse
    {
        $this->authorize('create', SalesOrder::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('sales-orders.show', $existing)
                    ->with('info', "Order [{$existing->reference}] was already taken.");
            }

            throw ValidationException::withMessages([
                'idempotency_key' => "This form already took order [{$existing->reference}]. "
                    .'Save again to take what is on screen now as a new order.',
            ]);
        }

        $data = $request->validated();

        try {
            $order = $this->desk->create($data, $data['lines'], $request->user());
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()
                    ->route('sales-orders.show', $existing)
                    ->with('info', "Order [{$existing->reference}] was already taken.");
            }

            throw $e;
        } catch (SalesOrderException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        } catch (QuotationException $e) {
            throw ValidationException::withMessages(['quotation_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales-orders.show', $order)
            ->with('success', $order->isApproved()
                ? "Order [{$order->reference}] was taken, and its stock is reserved until {$order->reserve_until->format('d M Y')}."
                : "Order [{$order->reference}] was taken. It reserves nothing until it is approved.");
    }

    public function show(Request $request, SalesOrder $salesOrder): Response
    {
        $this->authorize('view', $salesOrder);

        $salesOrder->auditView();

        return Inertia::render('SalesOrders/Show', array_merge(
            ['title' => $salesOrder->reference],
            $this->detail($request, $salesOrder),
        ));
    }

    /** The same order, as JSON, for the dialog on the list screen. */
    public function preview(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $this->authorize('view', $salesOrder);

        $salesOrder->auditView();

        return response()->json($this->detail($request, $salesOrder));
    }

    /**
     * Everything an order shows, on the page or in the dialog.
     *
     * @return array{order: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, SalesOrder $order): array
    {
        $order->load([
            'customer:id,name,code',
            'location:id,name',
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.product:id,sku,name,unit_id,on_hand',
            'lines.product.unit:id,code',
            'lines.unit:id,code',
            'createdBy:id,name',
            'approvedBy:id,name',
            'cancelledBy:id,name',
            'quotation:id,reference',
            'sale:id,reference,status',
        ]);

        $user = $request->user();
        $standing = $order->standing();
        // What others hold at its place, and what is there, so a pending
        // order can say whether it would fit (phase 31.4: at its location).
        $productIds = $order->lines->pluck('product_id')->unique()->values()->all();
        $held = Reservations::reserved($productIds, $order->id, locationId: $order->location_id);
        $there = Reservations::atLocation($productIds, (int) $order->location_id);
        $free = fn ($line) => Reservations::available(Quantity::fromMilli($there[$line->product_id] ?? 0), $held[$line->product_id] ?? 0);

        return [
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference,
                'order_date' => $order->order_date?->format('d M Y'),
                'reserve_until' => $order->reserve_until?->format('d M Y'),
                'reserve_until_iso' => $order->reserve_until?->toDateString(),
                'status' => $standing['value'],
                'status_label' => $standing['label'],
                'status_tone' => $standing['tone'],
                'customer' => $order->customer?->name,
                'customer_id' => $order->customer_id,
                // Where it holds its stock (phase 31.4).
                'location' => $order->location?->name,
                'attention' => $order->attention,
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'total_amount' => $order->total_amount,
                'notes' => $order->notes,
                'created_at' => $order->created_at?->format('d M Y, g:i A'),
                'created_by' => $order->createdBy?->name,
                'approved_at' => $order->approved_at?->format('d M Y, g:i A'),
                'approved_by' => $order->approvedBy?->name,
                'quotation' => $order->quotation ? ['id' => $order->quotation->id, 'reference' => $order->quotation->reference] : null,
                'sale' => $order->sale ? [
                    'id' => $order->sale->id,
                    'reference' => $order->sale->reference,
                    'status_label' => $order->sale->status->label(),
                ] : null,
                'fulfilled_at' => $order->fulfilled_at?->format('d M Y, g:i A'),
                'cancelled_at' => $order->cancelled_at?->format('d M Y, g:i A'),
                'cancelled_by' => $order->cancelledBy?->name,
                'cancel_reason' => $order->cancel_reason,
                'lines' => $order->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'unit' => $line->product?->unit?->code,
                    'unit_code' => $line->unit?->code ?? $line->product?->unit?->code,
                    'quantity_label' => UnitConversion::describe(
                        $line->unit_quantity,
                        $line->unit?->code,
                        $line->quantity,
                        $line->product?->unit?->code,
                        $line->unit_factor,
                    ),
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                    // Free for this order at its place: what is there less
                    // what other orders hold there.
                    'free' => $free($line),
                    'short' => Quantity::toMilli($line->quantity) > Quantity::toMilli($free($line)),
                ])->all(),
            ],
            'can' => [
                'approve' => $user->can('approve', $order),
                'cancel' => $user->can('cancel', $order),
                'sell' => $user->can('sell', $order),
                'viewSales' => $user->can('viewAny', Sale::class),
                'viewQuotations' => $user->can('viewAny', Quotation::class),
            ],
        ];
    }

    public function approve(ApproveSalesOrderRequest $request, SalesOrder $salesOrder): RedirectResponse
    {
        $this->authorize('approve', $salesOrder);

        try {
            $order = $this->desk->approve($salesOrder, $request->user(), Carbon::parse($request->validated()['reserve_until']));
        } catch (SalesOrderException $e) {
            throw ValidationException::withMessages(['reserve_until' => $e->getMessage()]);
        }

        return back()->with('success', "Order [{$order->reference}] was approved. Its stock is reserved until {$order->reserve_until->format('d M Y')}.");
    }

    public function cancel(CancelSalesOrderRequest $request, SalesOrder $salesOrder): RedirectResponse
    {
        $this->authorize('cancel', $salesOrder);

        try {
            $this->desk->cancel($salesOrder, $request->user(), $request->validated()['reason']);
        } catch (SalesOrderException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Order [{$salesOrder->reference}] was cancelled; anything it held is free again.");
    }

    /**
     * @return array{status:?string, customer_id:?int, created_by:?int, from:?string, to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,reserving,expired,fulfilled,cancelled'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'created_by' => isset($validated['created_by']) ? (int) $validated['created_by'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }
}
