<?php

namespace App\Http\Controllers;

use App\Enums\QuotationStatus;
use App\Exceptions\QuotationException;
use App\Http\Requests\Quotation\CancelQuotationRequest;
use App\Http\Requests\Quotation\StoreQuotationRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Services\QuotationDesk;
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
 * Prices offered to customers, valid until a date (phase 28).
 *
 * Shaped like the document modules — no edit, no delete, one `detail()` the
 * page and the dialog both read — but a quotation moves nothing: it is
 * accepted when it becomes a sale (on the sale form, `?quotation=`),
 * cancelled with a reason, or left to expire and quoted again. Its own page
 * prints as the quotation handed to the customer.
 */
class QuotationController extends Controller
{
    public function __construct(protected QuotationDesk $desk) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Quotation::class);

        $filters = $this->validatedFilters($request);
        $today = Carbon::today();

        $query = Quotation::query()
            ->with(['customer:id,name', 'preparedBy:id,name', 'sale:id,reference'])
            ->withCount('lines')
            // "Live" and "expired" are both open; the date tells them apart.
            ->when($filters['status'] === 'live', fn ($q) => $q->live($today))
            ->when($filters['status'] === 'expired', fn ($q) => $q->expired($today))
            ->when(in_array($filters['status'], [QuotationStatus::Accepted->value, QuotationStatus::Cancelled->value], true),
                fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['customer_id'], fn ($q, $id) => $q->where('customer_id', $id))
            ->when($filters['prepared_by'], fn ($q, $id) => $q->where('prepared_by', $id))
            ->when($filters['from'], fn ($q, $from) => $q->where('quote_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->where('quote_date', '<', Carbon::parse($to)->addDay()->toDateString()));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'attention', 'customer.name'])
            ->sortable(['reference', 'quote_date', 'valid_until', 'total_amount', 'status'])
            ->defaultSort('quote_date', 'desc')
            ->extra($filters);

        $user = $request->user();

        return Inertia::render('Quotations/Index', [
            'title' => 'Quotations',
            'quotations' => $table->paginate()->through(function (Quotation $quotation) use ($today, $user) {
                $standing = $quotation->standing($today);

                return [
                    'id' => $quotation->id,
                    'reference' => $quotation->reference,
                    'quote_date' => $quotation->quote_date?->format('d M Y'),
                    'valid_until' => $quotation->valid_until?->format('d M Y'),
                    'customer' => $quotation->customer?->name,
                    'attention' => $quotation->attention,
                    'prepared_by' => $quotation->preparedBy?->name,
                    'sale' => $quotation->sale?->reference,
                    'lines_count' => $quotation->lines_count,
                    'total_amount' => $quotation->total_amount,
                    'status' => $standing['value'],
                    'status_label' => $standing['label'],
                    'status_tone' => $standing['tone'],
                    'can' => ['sell' => $user->can('sell', $quotation)],
                ];
            }),
            'filters' => $table->state(),
            'options' => [
                'statuses' => [
                    ['value' => 'live', 'label' => 'Open, still valid'],
                    ['value' => 'expired', 'label' => 'Expired'],
                    ['value' => QuotationStatus::Accepted->value, 'label' => 'Accepted (became a sale)'],
                    ['value' => QuotationStatus::Cancelled->value, 'label' => 'Cancelled'],
                ],
                'customers' => Customer::options(),
                'staff' => StaffOptions::all(),
            ],
            'live' => Quotation::query()->live($today)->count(),
            'can' => ['create' => $user->can('create', Quotation::class)],
        ]);
    }

    /**
     * The form. `?from=` quotes an earlier quotation again — its customer
     * and lines, at today's prices, valid from today. `?customer=` starts
     * one for a customer.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', Quotation::class);

        $withCost = $request->user()->can('viewCost', Sale::class);
        $today = Carbon::today();
        $from = $request->filled('from') ? $this->requote(Quotation::find($request->integer('from'))) : null;

        return Inertia::render('Quotations/Create', [
            'title' => 'New quotation',
            'customers' => Customer::options(activeOnly: true),
            'products' => Product::sellingOptions($withCost),
            'customerId' => $from['customer_id'] ?? ($request->integer('customer') ?: null),
            'fromQuotation' => $from,
            'today' => $today->toDateString(),
            // Valid for the days the setting says, from today.
            'validUntil' => $today->copy()->addDays(max(1, (int) settings('documents.quotation_validity_days')))->toDateString(),
            'idempotencyKey' => (string) Str::uuid(),
            'can' => ['viewCost' => $withCost],
        ]);
    }

    public function store(StoreQuotationRequest $request): RedirectResponse
    {
        $this->authorize('create', Quotation::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('quotations.show', $existing)
                    ->with('info', "Quotation [{$existing->reference}] was already made.");
            }

            throw ValidationException::withMessages([
                'idempotency_key' => "This form already made quotation [{$existing->reference}]. "
                    .'Save again to make what is on screen now as a new quotation.',
            ]);
        }

        $data = $request->validated();

        try {
            $quotation = $this->desk->create($data, $data['lines'], $request->user());
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()
                    ->route('quotations.show', $existing)
                    ->with('info', "Quotation [{$existing->reference}] was already made.");
            }

            throw $e;
        } catch (QuotationException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('quotations.show', $quotation)
            ->with('success', "Quotation [{$quotation->reference}] was made, valid until {$quotation->valid_until->format('d M Y')}.");
    }

    public function show(Request $request, Quotation $quotation): Response
    {
        $this->authorize('view', $quotation);

        $quotation->auditView();

        return Inertia::render('Quotations/Show', array_merge(
            ['title' => $quotation->reference],
            $this->detail($request, $quotation),
        ));
    }

    /** The same quotation, as JSON, for the dialog on the list screen. */
    public function preview(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorize('view', $quotation);

        $quotation->auditView();

        return response()->json($this->detail($request, $quotation));
    }

    /**
     * Everything a quotation shows, on the page, in the dialog and on paper.
     *
     * The VAT split follows the Tax Settings switch, as a sale's does: it is
     * a view over what is charged, and a quotation is what would be charged.
     *
     * @return array{quotation: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, Quotation $quotation): array
    {
        $quotation->load([
            'customer:id,name,code,address,tin,contact_person,phone',
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.product:id,sku,name,unit_id,on_hand',
            'lines.product.unit:id,code',
            'lines.unit:id,code',
            'preparedBy:id,name',
            'cancelledBy:id,name',
            'sale:id,reference,status',
            'salesOrder:id,reference,quotation_id,status',
        ]);

        $user = $request->user();
        $standing = $quotation->standing();
        $rate = Vat::currentRate();
        $vat = Vat::split($quotation->total_amount, $rate);

        return [
            'quotation' => [
                'id' => $quotation->id,
                'reference' => $quotation->reference,
                'quote_date' => $quotation->quote_date?->format('d M Y'),
                'valid_until' => $quotation->valid_until?->format('d M Y'),
                'status' => $standing['value'],
                'status_label' => $standing['label'],
                'status_tone' => $standing['tone'],
                'customer' => $quotation->customer ? [
                    'id' => $quotation->customer->id,
                    'name' => $quotation->customer->name,
                    'code' => $quotation->customer->code,
                    'address' => $quotation->customer->address,
                    'tin' => $quotation->customer->tin,
                    'contact_person' => $quotation->customer->contact_person,
                    'phone' => $quotation->customer->phone,
                ] : null,
                'attention' => $quotation->attention,
                'subtotal' => $quotation->subtotal,
                'discount' => $quotation->discount,
                'total_amount' => $quotation->total_amount,
                // A string, so the page and the dialog's JSON read the same.
                'vat_rate' => number_format($rate, 2, '.', ''),
                'net_amount' => $vat['net'],
                'vat_amount' => $vat['vat'],
                'notes' => $quotation->notes,
                'prepared_at' => $quotation->created_at?->format('d M Y, g:i A'),
                'prepared_by' => $quotation->preparedBy?->name,
                'sale' => $quotation->sale ? [
                    'id' => $quotation->sale->id,
                    'reference' => $quotation->sale->reference,
                    'status_label' => $quotation->sale->status->label(),
                ] : null,
                // The sales order it became instead (phase 29), if any.
                'order' => $quotation->salesOrder ? [
                    'id' => $quotation->salesOrder->id,
                    'reference' => $quotation->salesOrder->reference,
                ] : null,
                'accepted_at' => $quotation->accepted_at?->format('d M Y, g:i A'),
                'cancelled_at' => $quotation->cancelled_at?->format('d M Y, g:i A'),
                'cancelled_by' => $quotation->cancelledBy?->name,
                'cancel_reason' => $quotation->cancel_reason,
                'lines' => $quotation->lines->map(fn ($line) => [
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
                    // What is on the shelf now; nothing is reserved for it.
                    'on_hand' => $line->product?->on_hand,
                    'short' => Quantity::toMilli($line->quantity) > Quantity::toMilli($line->product?->on_hand),
                ])->all(),
            ],
            'can' => [
                'sell' => $user->can('sell', $quotation),
                // Or take it as an order, reserving the stock (phase 29).
                'order' => $quotation->isLive() && $user->can('create', SalesOrder::class),
                'cancel' => $user->can('cancel', $quotation),
                'requote' => $user->can('create', Quotation::class),
                'viewSales' => $user->can('viewAny', Sale::class),
            ],
        ];
    }

    public function cancel(CancelQuotationRequest $request, Quotation $quotation): RedirectResponse
    {
        $this->authorize('cancel', $quotation);

        try {
            $this->desk->cancel($quotation, $request->user(), $request->validated()['reason']);
        } catch (QuotationException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Quotation [{$quotation->reference}] was cancelled.");
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * An earlier quotation, ready to quote again: same customer and lines, at
     * today's prices. A product switched off since is left out; a unit no
     * longer offered falls back to the product's own unit. What was left out
     * is said, so nothing disappears without a word.
     *
     * @return array<string, mixed>|null
     */
    protected function requote(?Quotation $source): ?array
    {
        if (! $source) {
            return null;
        }

        $source->load(['lines' => fn ($q) => $q->orderBy('id'), 'lines.product' => fn ($q) => $q->with(UnitConversion::WITH)]);

        $lines = [];
        $dropped = [];

        foreach ($source->lines as $line) {
            $product = $line->product;

            if (! $product || ! $product->is_active) {
                $dropped[] = ($product?->name ?? 'A product').' is no longer sold.';

                continue;
            }

            $options = collect(UnitConversion::options($product));
            $wanted = $line->unit_id ?? $product->unit_id;
            $option = $options->firstWhere('value', (int) $wanted);

            if ($option) {
                $lines[] = [
                    'product_id' => $product->id,
                    'unit_id' => $line->unit_id ?? '',
                    'quantity' => $line->unit_quantity,
                    'unit_price' => $option['price'],
                ];
            } else {
                $own = $options->first();
                $dropped[] = $product->name.' is no longer sold by that unit, so it is quoted by the '.$own['label'].'.';

                $lines[] = [
                    'product_id' => $product->id,
                    'unit_id' => '',
                    'quantity' => $line->quantity,
                    'unit_price' => $own['price'],
                ];
            }
        }

        return [
            'id' => $source->id,
            'reference' => $source->reference,
            'customer_id' => $source->customer_id,
            'attention' => $source->attention,
            'notes' => $source->notes,
            'lines' => $lines,
            'dropped' => $dropped,
        ];
    }

    /**
     * @return array{status:?string, customer_id:?int, prepared_by:?int, from:?string, to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:live,expired,accepted,cancelled'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'prepared_by' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'prepared_by' => isset($validated['prepared_by']) ? (int) $validated['prepared_by'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }
}
