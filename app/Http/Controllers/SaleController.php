<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\PaymentException;
use App\Exceptions\PendingPaymentException;
use App\Exceptions\QuotationException;
use App\Exceptions\SalesOrderException;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Http\Requests\Sale\VoidSaleRequest;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Services\SalePoster;
use App\Support\Quantity;
use App\Support\Reservations;
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
 * Goods sold.
 *
 * The mirror of `PurchaseController`, and the same rules: no edit, no
 * delete, void while it can be undone, return afterwards.
 *
 * What the goods cost, and so what the sale earned, is held back behind
 * `sales.view_cost`. Everything the screens receive is filtered through
 * that, not merely hidden once it has arrived.
 */
class SaleController extends Controller
{
    public function __construct(protected SalePoster $poster) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Sale::class);

        $filters = $this->validatedFilters($request);
        $seesCost = $request->user()->can('viewCost', Sale::class);

        $query = Sale::query()
            ->with(['customer:id,name', 'location:id,name'])
            ->withCount('lines')
            ->when($filters['customer_id'], fn ($q, $id) => $q->where('customer_id', $id))
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('sale_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('sale_date', '<=', $to))
            // Who posted it. The dashboard links here with it set.
            ->when($filters['posted_by'], fn ($q, $id) => $q->where('posted_by', $id));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'customer.name'])
            ->sortable(['reference', 'sale_date', 'total_amount', 'status', 'created_at'])
            ->defaultSort('sale_date', 'desc')
            ->extra($filters);

        return Inertia::render('Sales/Index', [
            'title' => 'Sales',
            'sales' => $table->paginate()->through(fn (Sale $sale) => array_merge([
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer' => $sale->customer?->name,
                'location' => $sale->location?->name,
                'sale_date' => $sale->sale_date?->format('d M Y'),
                'lines_count' => $sale->lines_count,
                'total_amount' => $sale->total_amount,
                'status' => $sale->status->value,
                'status_label' => $sale->status->label(),
                'status_tone' => $sale->status->tone(),
                'can' => ['void' => $request->user()->can('void', $sale)],
            ], $seesCost ? [
                'total_cost' => $sale->total_cost,
                'gross_profit' => $sale->grossProfit(),
            ] : [])),
            'filters' => $table->state(),
            'options' => [
                'customers' => $this->customerOptions(),
                'locations' => Location::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name])
                    ->all(),
                'staff' => StaffOptions::all(),
                'statuses' => [
                    ['value' => DocumentStatus::Posted->value, 'label' => 'Posted'],
                    ['value' => DocumentStatus::Voided->value, 'label' => 'Voided'],
                ],
            ],
            'can' => [
                'create' => $request->user()->can('create', Sale::class),
                'viewCost' => $seesCost,
            ],
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $this->authorize('create', Sale::class);

        // Made from a quotation (phase 28): its customer, lines at the quoted
        // prices and its discount, so nothing is typed again. Stock is
        // checked again now, and once more when the sale posts.
        $fromQuotation = null;

        if ($request->filled('quotation')) {
            $quotation = Quotation::with([
                'customer:id,name',
                'lines' => fn ($q) => $q->orderBy('id'),
                'lines.product:id,sku,name,on_hand,unit_id',
                'lines.product.unit:id,code',
            ])->find($request->integer('quotation'));

            if (! $quotation || ! $quotation->isLive()) {
                return redirect()
                    ->route('sales.create')
                    ->with('error', $quotation
                        ? "Quotation [{$quotation->reference}] is ".strtolower($quotation->standing()['label']).', so it cannot become a sale. Quote it again instead.'
                        : 'That quotation was not found.');
            }

            $fromQuotation = [
                'id' => $quotation->id,
                'reference' => $quotation->reference,
                'customer_id' => $quotation->customer_id,
                'customer' => $quotation->customer?->name,
                'valid_until' => $quotation->valid_until?->format('d M Y'),
                'discount' => number_format((float) $quotation->discount, 2, '.', ''),
                'notes' => $quotation->notes,
                'lines' => $quotation->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id ?? '',
                    'quantity' => $line->unit_quantity,
                    'unit_price' => number_format((float) $line->unit_price, 2, '.', ''),
                ])->all(),
                // What is short now: a quotation reserves nothing.
                'short' => $quotation->lines
                    ->filter(fn ($line) => Quantity::toMilli($line->quantity) > Quantity::toMilli($line->product?->on_hand))
                    ->map(fn ($line) => $line->product?->name.' — quoted '.Quantity::format($line->quantity)
                        .', on hand '.Quantity::format($line->product?->on_hand).' '.($line->product?->unit?->code ?? ''))
                    ->values()
                    ->all(),
            ];
        }

        // Fulfilling a sales order (phase 29): its customer, lines and
        // discount, with its own reservation free for this sale to take.
        $fromOrder = null;

        if ($request->filled('order')) {
            $order = SalesOrder::with(['customer:id,name', 'lines' => fn ($q) => $q->orderBy('id')])
                ->find($request->integer('order'));

            if (! $order || ! $order->isApproved()) {
                return redirect()
                    ->route('sales.create')
                    ->with('error', $order
                        ? "Order [{$order->reference}] is ".strtolower($order->standing()['label']).'. Only an approved order becomes a sale.'
                        : 'That order was not found.');
            }

            $fromOrder = [
                'id' => $order->id,
                'reference' => $order->reference,
                // Where its stock is held; the sale starts there (phase 31.4).
                'location_id' => $order->location_id,
                'customer_id' => $order->customer_id,
                'customer' => $order->customer?->name,
                'reserve_until' => $order->reserve_until?->format('d M Y'),
                'expired' => $order->isExpired(),
                'discount' => number_format((float) $order->discount, 2, '.', ''),
                'notes' => $order->notes,
                'lines' => $order->lines->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id ?? '',
                    'quantity' => $line->unit_quantity,
                    'unit_price' => number_format((float) $line->unit_price, 2, '.', ''),
                ])->all(),
            ];
        }

        // Where the goods leave the shelf (phase 31.4): the one picked, else
        // where the order holds its stock, else the default. Only an active
        // location; the form reloads the products when it changes.
        $location = Location::startingAt($request->integer('location') ?: null, $fromOrder['location_id'] ?? null);

        if ($fromQuotation && $location) {
            // What is short at that place now: a quotation reserves nothing.
            $there = Reservations::atLocation($quotation->lines->pluck('product_id')->unique()->values()->all(), $location->id);
            $code = fn ($line) => $line->product?->unit?->code ?? '';

            $fromQuotation['short'] = $quotation->lines
                ->filter(fn ($line) => Quantity::toMilli($line->quantity) > ($there[$line->product_id] ?? 0))
                ->map(fn ($line) => $line->product?->name.' — quoted '.Quantity::format($line->quantity)
                    .', on hand at '.$location->name.' '.Quantity::format(Quantity::fromMilli($there[$line->product_id] ?? 0)).' '.$code($line))
                ->values()
                ->all();
        }

        return Inertia::render('Sales/Create', [
            'title' => 'New sale',
            'locations' => Location::options(),
            'locationId' => $location?->id,
            'fromQuotation' => $fromQuotation,
            'fromOrder' => $fromOrder,
            // With what credit each has, so the form can offer "On account".
            'customers' => Customer::options(activeOnly: true, withCredit: true),
            'products' => Product::sellingOptions($request->user()->can('viewCost', Sale::class), $fromOrder['id'] ?? null, $location?->id),
            'walkInId' => Customer::where('is_walk_in', true)->value('id'),
            // How it may be paid: cash first, then the rest by name.
            'paymentMethods' => PaymentMethod::options(),
            'dates' => [
                'min' => InventoryMovement::max('movement_date')
                    ? Carbon::parse(InventoryMovement::max('movement_date'))->toDateString()
                    : null,
                'max' => Carbon::today()->toDateString(),
                'today' => Carbon::today()->toDateString(),
            ],
            'idempotencyKey' => (string) Str::uuid(),
            'can' => [
                'viewCost' => $request->user()->can('viewCost', Sale::class),
                // May give a sale on account more days than the customer's
                // usual terms (phase 18.1).
                'extendTerms' => $request->user()->can('customers.credit'),
            ],
        ]);
    }

    public function store(StoreSaleRequest $request): RedirectResponse
    {
        $this->authorize('create', Sale::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('sales.show', $existing)
                    ->with('info', "Sale [{$existing->reference}] was already recorded.");
            }

            throw ValidationException::withMessages([
                'idempotency_key' => "This form already recorded sale [{$existing->reference}]. "
                    .'Save again to record what is on screen now as a new sale.',
            ]);
        }

        $data = $request->validated();

        try {
            $sale = $this->poster->post($data, $data['lines']);
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()
                    ->route('sales.show', $existing)
                    ->with('info', "Sale [{$existing->reference}] was already recorded.");
            }

            throw $e;
        } catch (InventoryException $e) {
            // The form already weighs each line against what is on hand, so
            // reaching here means somebody sold the last of it in between.
            throw ValidationException::withMessages([
                str_contains($e->getMessage(), 'dated') ? 'sale_date' : 'lines' => $e->getMessage(),
            ]);
        } catch (PaymentException $e) {
            throw ValidationException::withMessages(['payments' => $e->getMessage()]);
        } catch (QuotationException $e) {
            // Turned into a sale or cancelled by somebody else a moment ago.
            throw ValidationException::withMessages(['quotation_id' => $e->getMessage()]);
        } catch (SalesOrderException $e) {
            throw ValidationException::withMessages(['sales_order_id' => $e->getMessage()]);
        } catch (PendingPaymentException $e) {
            // Paid or cancelled on another screen a moment ago, or changed.
            throw ValidationException::withMessages(['pending_payment_id' => $e->getMessage()]);
        }

        // The change is the figure the cashier is about to hand over, so it
        // is said again once the sale is in, not only while typing.
        $message = "Sale [{$sale->reference}] was posted.";
        $change = $sale->changeGiven();

        if (bccomp($change, '0', 2) > 0) {
            $message .= ' Change: '.settings('system.currency_symbol', '₱').number_format((float) $change, 2).'.';
        }

        if ((float) $sale->on_account > 0) {
            $message .= ' On account: '.settings('system.currency_symbol', '₱').number_format((float) $sale->on_account, 2)
                .', due '.$sale->due_date?->format('d M Y')
                .' ('.$sale->terms_days.' '.($sale->terms_days === 1 ? 'day' : 'days').').';
        }

        // The till sends the next customer's order from the same screen,
        // so it goes back to the till rather than to the document. The
        // sale itself is identical either way.
        if ($request->string('from')->toString() === 'pos') {
            return redirect()
                // Back to the same place: the till keeps selling from it.
                ->route('pos', ['location' => $sale->location_id])
                ->with('success', $message)
                // So the till can offer the slip for the sale just rung up.
                ->with('sale', ['id' => $sale->id, 'reference' => $sale->reference]);
        }

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', $message);
    }

    public function show(Request $request, Sale $sale): Response
    {
        $this->authorize('view', $sale);

        $sale->auditView();

        return Inertia::render('Sales/Show', array_merge(
            ['title' => $sale->reference],
            $this->detail($request, $sale),
        ));
    }

    /**
     * The same sale, as JSON, for the dialog on the list screen.
     *
     * Both this and `show()` call `detail()`, so the dialog can never show
     * a different figure from the page — including the cost, which is left
     * out of the payload entirely for anyone without `sales.view_cost`.
     */
    public function preview(Request $request, Sale $sale): JsonResponse
    {
        $this->authorize('view', $sale);

        $sale->auditView();

        return response()->json($this->detail($request, $sale));
    }

    /**
     * Everything a sale shows, on the page or in the dialog.
     *
     * @return array{sale: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, Sale $sale): array
    {
        $sale->load([
            'customer:id,name,code',
            'location:id,name',
            'lines.product:id,sku,name,unit_id',
            'lines.product.unit:id,code',
            'lines.unit:id,code',
            'payments.method:id,name,kind',
            'postedBy:id,name',
            'voidedBy:id,name',
            // What customers paid against it since, voided payments left out.
            'allocations' => fn ($q) => $q->whereHas('payment', fn ($p) => $p->posted())->orderBy('id'),
            'allocations.payment:id,reference,payment_date',
            'quotation:id,reference,sale_id',
            'salesOrder:id,reference,sale_id',
        ]);

        $seesCost = $request->user()->can('viewCost', $sale);

        // The rate the switch is set to right now. This is a view for
        // comparing VAT against non-VAT, so every sale follows the switch,
        // old ones included. A line is split on its own gross total; the
        // document is split on what was actually charged, after the
        // discount.
        $rate = Vat::currentRate();
        $vat = $sale->vatSplit();

        return [
            'sale' => array_merge([
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer' => $sale->customer?->name,
                'customer_code' => $sale->customer?->code,
                // Where the goods left the shelf (phase 31.4).
                'location' => $sale->location?->name,
                // The sales order it fulfilled (phase 29), if any.
                'sales_order' => $sale->salesOrder ? [
                    'id' => $sale->salesOrder->id,
                    'reference' => $sale->salesOrder->reference,
                ] : null,
                // The quotation it was made from (phase 28), if any.
                'quotation' => $sale->quotation ? [
                    'id' => $sale->quotation->id,
                    'reference' => $sale->quotation->reference,
                ] : null,
                'sale_date' => $sale->sale_date?->format('d M Y'),
                'status' => $sale->status->value,
                'status_label' => $sale->status->label(),
                'status_tone' => $sale->status->tone(),
                'subtotal' => $sale->subtotal,
                'discount' => $sale->discount,
                'total_amount' => $sale->total_amount,
                'vat_rate' => Vat::rateLabel($rate),
                'net_amount' => $vat['net'],
                'vat_amount' => $vat['vat'],
                'notes' => $sale->notes,
                // How it was paid. Each row is what that payment put towards
                // the sale; on cash, what was handed over as well.
                'payments' => $sale->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'method' => $payment->method?->name,
                    'kind' => $payment->method?->kind?->value,
                    'amount' => $payment->amount,
                    'tendered' => $payment->tendered,
                    'reference' => $payment->reference,
                ])->all(),
                'change' => $sale->changeGiven(),
                // What was not paid at the counter, and what is still owed.
                'on_account' => $sale->on_account,
                'balance_due' => $sale->balance_due,
                'due_date' => $sale->due_date?->format('d M Y'),
                // The days agreed on this sale (phase 18.1).
                'terms_days' => $sale->terms_days,
                'account_payments' => $sale->allocations->map(fn ($allocation) => [
                    'id' => $allocation->customer_payment_id,
                    'reference' => $allocation->payment?->reference,
                    'payment_date' => $allocation->payment?->payment_date?->format('d M Y'),
                    'amount' => $allocation->amount,
                ])->all(),
                'posted_at' => $sale->posted_at?->format('d M Y, g:i A'),
                'posted_by' => $sale->postedBy?->name,
                'voided_at' => $sale->voided_at?->format('d M Y, g:i A'),
                'voided_by' => $sale->voidedBy?->name,
                'void_reason' => $sale->void_reason,
                'lines' => $sale->lines->map(fn ($line) => array_merge([
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'unit' => $line->product?->unit?->code,
                    // As sold: 1 box at 450. A line sold in the product's
                    // own unit reads the same both ways.
                    'converted' => $line->unit_id !== null,
                    'unit_quantity' => $line->unit_quantity,
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
                    'net_total' => Vat::net($line->line_total, $rate),
                    'vat_total' => Vat::tax($line->line_total, $rate),
                ], $seesCost ? [
                    'unit_cost' => $line->unit_cost,
                    'line_cost' => $line->line_cost,
                ] : []))->all(),
            ], $seesCost ? [
                'total_cost' => $sale->total_cost,
                'gross_profit' => $sale->grossProfit(),
            ] : []),
            'can' => [
                // A sale with a payment against it cannot be voided: the
                // payment is voided first. The list asks nothing per row; this
                // one record may.
                'void' => $request->user()->can('void', $sale) && $sale->allocations->isEmpty(),
                'viewCost' => $seesCost,
                // A return starts here: there is no way to make one out of
                // thin air, because it has to know which batches to refill.
                'return' => $sale->isPosted()
                    && $request->user()->can('create', SalesReturn::class)
                    && $sale->lines->sum(fn ($line) => $line->returnable()) > 0,
            ],
        ];
    }

    public function void(VoidSaleRequest $request, Sale $sale): RedirectResponse
    {
        $this->authorize('void', $sale);

        try {
            $this->poster->void($sale, $request->validated()['reason']);
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Sale [{$sale->reference}] was voided.");
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * @return array{customer_id:?int,location_id:?int,status:?string,from:?string,to:?string,posted_by:?int}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => ['nullable', 'in:'.implode(',', DocumentStatus::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'posted_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return [
            'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'posted_by' => isset($validated['posted_by']) ? (int) $validated['posted_by'] : null,
        ];
    }

    /**
     * @return list<array{value:int,label:string,walk_in:bool}>
     */
    protected function customerOptions(bool $active = false): array
    {
        return Customer::options(activeOnly: $active);
    }
}
