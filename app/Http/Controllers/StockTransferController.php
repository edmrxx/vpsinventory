<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\LocationType;
use App\Exceptions\InventoryException;
use App\Http\Requests\Transfer\StoreTransferRequest;
use App\Http\Requests\Transfer\VoidTransferRequest;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\TransferPoster;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\StaffOptions;
use App\Support\TableQuery;
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
 * Stock moved from one location to another (phase 31.5): the warehouse
 * sends goods to the store, the store sends back what it does not sell.
 *
 * Shaped like the adjustments: no edit and no delete, a void while what the
 * transfer brought in is untouched.
 */
class StockTransferController extends Controller
{
    public function __construct(protected TransferPoster $poster) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', StockTransfer::class);

        $filters = $this->validatedFilters($request);

        $query = StockTransfer::query()
            ->with(['fromLocation:id,name', 'toLocation:id,name'])
            ->withCount('lines')
            ->when($filters['from_location_id'], fn ($q, $id) => $q->where('from_location_id', $id))
            ->when($filters['to_location_id'], fn ($q, $id) => $q->where('to_location_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('transfer_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('transfer_date', '<=', $to))
            ->when($filters['posted_by'], fn ($q, $id) => $q->where('posted_by', $id));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'notes'])
            ->sortable(['reference', 'transfer_date', 'status', 'total_cost', 'created_at'])
            ->defaultSort('transfer_date', 'desc')
            ->extra($filters);

        return Inertia::render('Transfers/Index', [
            'title' => 'Stock Transfers',
            'transfers' => $table->paginate()->through(fn (StockTransfer $transfer) => [
                'id' => $transfer->id,
                'reference' => $transfer->reference,
                'transfer_date' => $transfer->transfer_date?->format('d M Y'),
                'from' => $transfer->fromLocation?->name,
                'to' => $transfer->toLocation?->name,
                'lines_count' => $transfer->lines_count,
                'total_cost' => $transfer->total_cost,
                'status' => $transfer->status->value,
                'status_label' => $transfer->status->label(),
                'status_tone' => $transfer->status->tone(),
            ]),
            'filters' => $table->state(),
            'options' => [
                'locations' => Location::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name])
                    ->all(),
                'staff' => StaffOptions::all(),
                'statuses' => [
                    ['value' => DocumentStatus::Posted->value, 'label' => 'Posted'],
                    ['value' => DocumentStatus::Voided->value, 'label' => 'Voided'],
                ],
            ],
            'can' => ['create' => $request->user()->can('create', StockTransfer::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', StockTransfer::class);

        // Where the goods are now: the one asked for, else the first active
        // warehouse — the usual way round is warehouse to store — else the
        // default. Where they go: the one asked for, else the default, else
        // the first other active place.
        $asked = $request->integer('from') ?: null;
        $from = $asked
            ? Location::startingAt($asked)
            : (Location::startingAt(null, null, LocationType::Warehouse) ?? Location::startingAt(null));

        $to = $this->destination($request->integer('to') ?: null, $from);

        return Inertia::render('Transfers/Create', [
            'title' => 'New transfer',
            'locations' => Location::options(),
            'fromLocationId' => $from?->id,
            'toLocationId' => $to?->id,
            'products' => $this->productOptions($from?->id),
            'dates' => [
                'min' => $this->floorDate(),
                'max' => Carbon::today()->toDateString(),
                'today' => Carbon::today()->toDateString(),
            ],
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreTransferRequest $request): RedirectResponse
    {
        $this->authorize('create', StockTransfer::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('transfers.show', $existing)
                    ->with('info', "Transfer [{$existing->reference}] was already recorded.");
            }

            throw ValidationException::withMessages([
                'idempotency_key' => "This form already recorded transfer [{$existing->reference}]. "
                    .'Save again to record what is on screen now as a new one.',
            ]);
        }

        $data = $request->validated();

        try {
            $transfer = $this->poster->post(
                array_map(fn (array $line) => [
                    'product_id' => (int) $line['product_id'],
                    'quantity' => $line['quantity'],
                ], $data['lines']),
                (int) $data['from_location_id'],
                (int) $data['to_location_id'],
                Carbon::parse($data['transfer_date']),
                $data['notes'] ?? null,
                $data['idempotency_key'] ?? null,
            );
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()->route('transfers.show', $existing);
            }

            throw $e;
        } catch (InventoryException $e) {
            throw ValidationException::withMessages([
                str_contains($e->getMessage(), 'dated') ? 'transfer_date' : 'lines' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('transfers.show', $transfer)
            ->with('success', "Transfer [{$transfer->reference}] was posted.");
    }

    public function show(Request $request, StockTransfer $transfer): Response
    {
        $this->authorize('view', $transfer);

        $transfer->auditView();

        return Inertia::render('Transfers/Show', array_merge(
            ['title' => $transfer->reference],
            $this->detail($request, $transfer),
        ));
    }

    /**
     * The same transfer, as JSON, for the dialog on the list screen.
     * Both this and `show()` call `detail()`, so the two cannot drift.
     */
    public function preview(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorize('view', $transfer);

        $transfer->auditView();

        return response()->json($this->detail($request, $transfer));
    }

    /**
     * Everything a transfer shows, on the page or in the dialog.
     *
     * The cost is the batches' own cost, the same figure an adjustment
     * shows, so it is not held back behind `sales.view_cost`.
     *
     * @return array{transfer: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, StockTransfer $transfer): array
    {
        $transfer->load([
            'lines.product:id,sku,name,unit_id', 'lines.product.unit:id,code',
            'postedBy:id,name', 'voidedBy:id,name', 'fromLocation:id,name', 'toLocation:id,name',
        ]);

        return [
            'transfer' => [
                'id' => $transfer->id,
                'reference' => $transfer->reference,
                'transfer_date' => $transfer->transfer_date?->format('d M Y'),
                'from' => $transfer->fromLocation?->name,
                'to' => $transfer->toLocation?->name,
                'notes' => $transfer->notes,
                'total_cost' => $transfer->total_cost,
                'status' => $transfer->status->value,
                'status_label' => $transfer->status->label(),
                'status_tone' => $transfer->status->tone(),
                'posted_at' => $transfer->posted_at?->format('d M Y, g:i A'),
                'posted_by' => $transfer->postedBy?->name,
                'voided_at' => $transfer->voided_at?->format('d M Y, g:i A'),
                'voided_by' => $transfer->voidedBy?->name,
                'void_reason' => $transfer->void_reason,
                'lines' => $transfer->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'unit' => $line->product?->unit?->code,
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->unit_cost,
                    'total_cost' => $line->total_cost,
                ])->all(),
            ],
            'can' => ['void' => $request->user()->can('void', $transfer)],
        ];
    }

    public function void(VoidTransferRequest $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('void', $transfer);

        try {
            $this->poster->void($transfer, $request->validated()['reason']);
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Transfer [{$transfer->reference}] was voided.");
    }

    /**
     * Where the goods go, by default: the one asked for, else the default,
     * else the first other active place — never where they already are.
     */
    protected function destination(?int $requested, ?Location $from): ?Location
    {
        $other = fn (?Location $l) => $l && $l->is_active && $l->id !== $from?->id ? $l : null;

        return $other($requested ? Location::find($requested) : null)
            ?? $other(Location::default())
            ?? Location::query()->where('is_active', true)
                ->when($from, fn ($q) => $q->whereKeyNot($from->id))
                ->orderBy('name')->first();
    }

    protected function floorDate(): ?string
    {
        $last = InventoryMovement::max('movement_date');

        return $last ? Carbon::parse($last)->toDateString() : null;
    }

    /**
     * The products, with what is free to move at the source: on hand
     * there, less what customer orders hold there. Two grouped reads.
     *
     * @return list<array<string, mixed>>
     */
    protected function productOptions(?int $locationId): array
    {
        $there = $locationId ? Reservations::atLocation(null, $locationId) : [];
        $held = $locationId ? Reservations::reserved(null, locationId: $locationId) : [];

        return Product::query()
            ->with('unit:id,code,allows_decimals')
            ->where('is_active', true)
            ->orderBy('sku')
            ->get(['id', 'sku', 'name', 'cost', 'on_hand', 'unit_id', ...Product::SEARCH_COLUMNS])
            ->map(fn (Product $p) => [
                'value' => $p->id,
                'label' => "{$p->sku} — {$p->name}",
                'keywords' => $p->searchWords(),
                'sku' => $p->sku,
                'cost' => $p->cost,
                'on_hand' => Reservations::available(Quantity::fromMilli($there[$p->id] ?? 0), $held[$p->id] ?? 0),
                'unit' => $p->unit?->code,
                'decimals' => (bool) $p->unit?->allows_decimals,
            ])
            ->all();
    }

    /**
     * @return array{from_location_id:?int,to_location_id:?int,status:?string,from:?string,to:?string,posted_by:?int}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'from_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'to_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => ['nullable', 'in:'.implode(',', DocumentStatus::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'posted_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return [
            'from_location_id' => isset($validated['from_location_id']) ? (int) $validated['from_location_id'] : null,
            'to_location_id' => isset($validated['to_location_id']) ? (int) $validated['to_location_id'] : null,
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'posted_by' => isset($validated['posted_by']) ? (int) $validated['posted_by'] : null,
        ];
    }
}
