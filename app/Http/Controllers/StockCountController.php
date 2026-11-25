<?php

namespace App\Http\Controllers;

use App\Enums\StockCountStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\StockCountException;
use App\Http\Requests\StockCount\CountReasonRequest;
use App\Http\Requests\StockCount\RecordCountsRequest;
use App\Http\Requests\StockCount\StartCountRequest;
use App\Models\Category;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Services\StockCountDesk;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\StaffOptions;
use App\Support\TableQuery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The count sheet (phase 32): start a count at a location, enter what is
 * found, hand it in, and approve it — which posts the differences as
 * adjustments — or send it back for a recount.
 *
 * Not a posted document: there is no void. A count is cancelled while open;
 * once approved, a mistake is corrected with an adjustment or another count,
 * like any posted figure.
 */
class StockCountController extends Controller
{
    public function __construct(protected StockCountDesk $desk) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', StockCount::class);

        $filters = $this->validatedFilters($request);

        $query = StockCount::query()
            ->with(['location:id,name', 'category:id,name'])
            ->withCount([
                'lines',
                'lines as counted_count' => fn ($q) => $q->whereNotNull('counted_quantity'),
            ])
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('count_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('count_date', '<=', $to))
            ->when($filters['started_by'], fn ($q, $id) => $q->where('started_by', $id));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'notes'])
            ->sortable(['reference', 'count_date', 'status', 'created_at'])
            ->defaultSort('count_date', 'desc')
            ->extra($filters);

        return Inertia::render('StockCounts/Index', [
            'title' => 'Stock Counts',
            'counts' => $table->paginate()->through(fn (StockCount $count) => [
                'id' => $count->id,
                'reference' => $count->reference,
                'count_date' => $count->count_date?->format('d M Y'),
                'location' => $count->location?->name,
                'scope' => $count->category?->name ?? 'All products',
                'lines_count' => $count->lines_count,
                'counted_count' => $count->counted_count,
                'status' => $count->status->value,
                'status_label' => $count->status->label(),
                'status_tone' => $count->status->tone(),
            ]),
            'filters' => $table->state(),
            'options' => [
                'locations' => Location::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name])
                    ->all(),
                'staff' => StaffOptions::all(),
                'statuses' => StockCountStatus::options(),
            ],
            'can' => ['create' => $request->user()->can('create', StockCount::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', StockCount::class);

        $location = Location::startingAt($request->integer('location') ?: null);

        return Inertia::render('StockCounts/Create', [
            'title' => 'New stock count',
            'locations' => Location::options(),
            'locationId' => $location?->id,
            'categories' => Category::orderBy('name')->get(['id', 'name'])
                ->map(fn (Category $c) => ['value' => $c->id, 'label' => $c->name])
                ->all(),
            // A location already being counted cannot start another; the
            // form says so before anybody presses Start.
            'open' => StockCount::query()
                ->whereIn('status', StockCountStatus::openValues())
                ->get(['id', 'reference', 'location_id'])
                ->mapWithKeys(fn (StockCount $c) => [$c->location_id => ['id' => $c->id, 'reference' => $c->reference]])
                ->all(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StartCountRequest $request): RedirectResponse
    {
        $this->authorize('create', StockCount::class);

        if ($existing = $request->alreadySaved()) {
            return redirect()
                ->route('stock-counts.show', $existing)
                ->with('info', "Count [{$existing->reference}] was already started.");
        }

        $data = $request->validated();

        try {
            $count = $this->desk->start(
                (int) $data['location_id'],
                isset($data['category_id']) ? (int) $data['category_id'] : null,
                $data['notes'] ?? null,
                $data['idempotency_key'] ?? null,
            );
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()->route('stock-counts.show', $existing);
            }

            throw $e;
        } catch (StockCountException $e) {
            throw ValidationException::withMessages(['location_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('stock-counts.show', $count)
            ->with('success', "Count [{$count->reference}] was started. The figures on the books were taken as of now.");
    }

    public function show(Request $request, StockCount $stockCount): Response
    {
        $this->authorize('view', $stockCount);

        $stockCount->auditView();

        return Inertia::render('StockCounts/Show', array_merge(
            ['title' => $stockCount->reference],
            $this->detail($request, $stockCount),
        ));
    }

    /**
     * The same count, as JSON, for the dialog on the list screen. Both this
     * and `show()` call `detail()`, so the two cannot drift.
     */
    public function preview(Request $request, StockCount $stockCount): JsonResponse
    {
        $this->authorize('view', $stockCount);

        $stockCount->auditView();

        return response()->json($this->detail($request, $stockCount));
    }

    /** Save what was counted. */
    public function record(RecordCountsRequest $request, StockCount $stockCount): RedirectResponse
    {
        $this->authorize('count', $stockCount);

        try {
            $changed = $this->desk->record($stockCount, $request->figures(), $request->user());
        } catch (StockCountException $e) {
            throw ValidationException::withMessages(['counts' => $e->getMessage()]);
        }

        return back()->with('success', $changed === 0
            ? 'Nothing had changed since the last save.'
            : "{$changed} counted figure(s) saved.");
    }

    public function submit(Request $request, StockCount $stockCount): RedirectResponse
    {
        $this->authorize('submit', $stockCount);

        try {
            $this->desk->submit($stockCount, $request->user());
        } catch (StockCountException $e) {
            throw ValidationException::withMessages(['count' => $e->getMessage()]);
        }

        return back()->with('success', "Count [{$stockCount->reference}] was handed in for approval.");
    }

    public function approve(Request $request, StockCount $stockCount): RedirectResponse
    {
        $this->authorize('approve', $stockCount);

        try {
            $count = $this->desk->approve($stockCount, $request->user());
        } catch (StockCountException|InventoryException $e) {
            throw ValidationException::withMessages(['count' => $e->getMessage()]);
        }

        $posted = array_filter([$count->gainAdjustment?->reference, $count->lossAdjustment?->reference]);

        return back()->with('success', $posted === []
            ? "Count [{$count->reference}] was approved. Everything counted matched the books."
            : "Count [{$count->reference}] was approved and posted as ".implode(' and ', $posted).'.');
    }

    public function sendBack(CountReasonRequest $request, StockCount $stockCount): RedirectResponse
    {
        $this->authorize('sendBack', $stockCount);

        try {
            $this->desk->sendBack($stockCount, $request->user(), $request->validated()['reason']);
        } catch (StockCountException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Count [{$stockCount->reference}] was sent back for a recount.");
    }

    public function cancel(CountReasonRequest $request, StockCount $stockCount): RedirectResponse
    {
        $this->authorize('cancel', $stockCount);

        try {
            $this->desk->cancel($stockCount, $request->user(), $request->validated()['reason']);
        } catch (StockCountException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Count [{$stockCount->reference}] was cancelled. Nothing was posted.");
    }

    /**
     * Everything a count shows, on the page or in the dialog.
     *
     * **A blind count.** While a count is open, a counter who may not
     * approve it is sent no book figure at all — not the cutoff figure, not
     * the difference — so what they write is what they found, not what they
     * were told to expect. The approver sees everything, and once a count is
     * finished everybody does: the adjustments it posted show the same.
     *
     * @return array{count: array<string, mixed>, summary: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, StockCount $count): array
    {
        $user = $request->user();

        $count->load([
            'location:id,name', 'category:id,name',
            'startedBy:id,name', 'submittedBy:id,name', 'sentBackBy:id,name',
            'approvedBy:id,name', 'cancelledBy:id,name',
            'gainAdjustment:id,reference,status', 'lossAdjustment:id,reference,status',
        ]);

        $lines = $count->lines()
            ->with([
                'product:id,sku,name,cost,unit_id,category_id',
                'product.unit:id,code,allows_decimals',
                'product.category:id,name',
                'countedBy:id,name',
            ])
            ->get()
            ->sortBy(fn (StockCountLine $line) => $line->product?->sku)
            ->values();

        $open = $count->isOpen();
        $seesBooks = ! $open || $user->can('stock_counts.approve');

        // What is at the location now, to show how far each line has moved
        // since the cutoff. Only while open, and only to those who see the
        // books: one grouped read.
        $now = $open && $seesBooks
            ? Reservations::atLocation($lines->pluck('product_id')->all(), $count->location_id)
            : [];

        // What pending loss reports hold aside there (phase 33): still on
        // the books, so counted too — the approver sees where a set-aside
        // box skipped by the counters would be taken off twice.
        $heldForLosses = $open && $seesBooks
            ? Reservations::heldForLosses($lines->pluck('product_id')->all(), $count->location_id)
            : [];

        $over = $short = $matched = 0;
        $estimatedGain = $estimatedLoss = 0;

        $rows = $lines->map(function (StockCountLine $line) use ($open, $seesBooks, $now, $heldForLosses, &$over, &$short, &$matched, &$estimatedGain, &$estimatedLoss) {
            $row = [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'sku' => $line->product?->sku,
                'name' => $line->product?->name,
                'category' => $line->product?->category?->name,
                'unit' => $line->product?->unit?->code,
                'decimals' => (bool) $line->product?->unit?->allows_decimals,
                'counted_quantity' => $line->counted_quantity,
                'counted_by' => $line->countedBy?->name,
            ];

            if (! $seesBooks) {
                return $row;
            }

            $difference = $line->isCounted()
                ? Quantity::toMilli($line->counted_quantity) - Quantity::toMilli($line->system_quantity)
                : null;

            if ($difference !== null) {
                match (true) {
                    $difference > 0 => $over++,
                    $difference < 0 => $short++,
                    default => $matched++,
                };
            }

            $row['system_quantity'] = $line->system_quantity;
            $row['difference'] = $difference === null ? null : Quantity::fromMilli($difference);

            if ($open) {
                $nowMilli = $now[$line->product_id] ?? 0;
                $row['changed_since'] = Quantity::fromMilli($nowMilli - Quantity::toMilli($line->system_quantity));
                $row['held_for_losses'] = Quantity::fromMilli($heldForLosses[$line->product_id] ?? 0);

                // An estimate at what the product costs now. The posted
                // figure comes from the batches a shortfall eats.
                $estimate = $difference === null ? 0 : Quantity::cents(abs($difference), $line->product?->cost);
                $row['estimate'] = ($difference < 0 ? -$estimate : $estimate) / 100;

                if ($difference > 0) {
                    $estimatedGain += $estimate;
                } elseif ($difference < 0) {
                    $estimatedLoss += $estimate;
                }
            } else {
                $row['variance'] = $line->variance;
                $row['variance_value'] = $line->variance_value;
            }

            return $row;
        })->all();

        $counted = $lines->filter(fn (StockCountLine $l) => $l->isCounted())->count();

        $adjustment = fn (?InventoryAdjustment $a) => $a ? [
            'id' => $a->id,
            'reference' => $a->reference,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
        ] : null;

        return [
            'count' => [
                'id' => $count->id,
                'reference' => $count->reference,
                'location' => $count->location?->name,
                'scope' => $count->category?->name ?? 'All products',
                'count_date' => $count->count_date?->format('d M Y'),
                'cutoff_at' => $count->cutoff_at?->format('d M Y, g:i A'),
                'notes' => $count->notes,
                'status' => $count->status->value,
                'status_label' => $count->status->label(),
                'status_tone' => $count->status->tone(),
                'blind' => ! $seesBooks,
                'started_by' => $count->startedBy?->name,
                'submitted_at' => $count->submitted_at?->format('d M Y, g:i A'),
                'submitted_by' => $count->submittedBy?->name,
                'sent_back_at' => $count->sent_back_at?->format('d M Y, g:i A'),
                'sent_back_by' => $count->sentBackBy?->name,
                'send_back_reason' => $count->send_back_reason,
                'approved_at' => $count->approved_at?->format('d M Y, g:i A'),
                'approved_by' => $count->approvedBy?->name,
                'cancelled_at' => $count->cancelled_at?->format('d M Y, g:i A'),
                'cancelled_by' => $count->cancelledBy?->name,
                'cancel_reason' => $count->cancel_reason,
                'gain_adjustment' => $adjustment($count->gainAdjustment),
                'loss_adjustment' => $adjustment($count->lossAdjustment),
                'lines' => $rows,
            ],
            'summary' => [
                'lines' => $lines->count(),
                'counted' => $counted,
                'uncounted' => $lines->count() - $counted,
            ] + ($seesBooks ? [
                'over' => $over,
                'short' => $short,
                'matched' => $matched,
                'gain_value' => $open ? $estimatedGain / 100 : (float) $count->gain_value,
                'loss_value' => $open ? $estimatedLoss / 100 : (float) $count->loss_value,
                'estimated' => $open,
            ] : []),
            'can' => [
                'count' => $user->can('count', $count),
                'submit' => $user->can('submit', $count),
                'approve' => $user->can('approve', $count),
                'send_back' => $user->can('sendBack', $count),
                'cancel' => $user->can('cancel', $count),
            ],
        ];
    }

    /**
     * @return array{location_id:?int,status:?string,from:?string,to:?string,started_by:?int}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => ['nullable', 'in:'.implode(',', StockCountStatus::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'started_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return [
            'location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'started_by' => isset($validated['started_by']) ? (int) $validated['started_by'] : null,
        ];
    }
}
