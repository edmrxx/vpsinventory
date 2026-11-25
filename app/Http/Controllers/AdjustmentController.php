<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Http\Requests\Adjustment\StoreAdjustmentRequest;
use App\Http\Requests\Adjustment\VoidAdjustmentRequest;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Services\AdjustmentPoster;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock that no purchase or sale explains: a breakage, a recount, and the
 * opening count a business starts with.
 *
 * Phase 7 built the table and the posting. This is the screen on top.
 */
class AdjustmentController extends Controller
{
    public function __construct(protected AdjustmentPoster $poster) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InventoryAdjustment::class);

        $filters = $this->validatedFilters($request);

        $query = InventoryAdjustment::query()
            ->with('location:id,name')
            ->withCount('lines')
            ->when($filters['kind'], fn ($q, $kind) => $q->where('kind', $kind))
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['reason'], fn ($q, $reason) => $q->where('reason', $reason))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('adjustment_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('adjustment_date', '<=', $to))
            // Who posted it. The dashboard links here with it set.
            ->when($filters['posted_by'], fn ($q, $id) => $q->where('posted_by', $id));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'reason', 'notes'])
            ->sortable(['reference', 'adjustment_date', 'kind', 'status', 'total_cost', 'created_at'])
            ->defaultSort('adjustment_date', 'desc')
            ->extra($filters);

        return Inertia::render('Adjustments/Index', [
            'title' => 'Stock Adjustments',
            'adjustments' => $table->paginate()->through(fn (InventoryAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'reference' => $adjustment->reference,
                'kind' => $adjustment->kind,
                'kind_label' => $this->kindLabel($adjustment->kind),
                'kind_tone' => $this->kindTone($adjustment->kind),
                'adjustment_date' => $adjustment->adjustment_date?->format('d M Y'),
                'location' => $adjustment->location?->name,
                'reason' => $this->reasonLabel($adjustment->reason),
                'lines_count' => $adjustment->lines_count,
                'total_cost' => $adjustment->total_cost,
                'status' => $adjustment->status->value,
                'status_label' => $adjustment->status->label(),
                'status_tone' => $adjustment->status->tone(),
                'can' => ['void' => $request->user()->can('void', $adjustment)],
            ]),
            'filters' => $table->state(),
            'options' => [
                'kinds' => $this->kindOptions(),
                'locations' => Location::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name])
                    ->all(),
                'staff' => StaffOptions::all(),
                'reasons' => collect(StoreAdjustmentRequest::REASONS)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
                'statuses' => [
                    ['value' => DocumentStatus::Posted->value, 'label' => 'Posted'],
                    ['value' => DocumentStatus::Voided->value, 'label' => 'Voided'],
                ],
            ],
            'can' => ['create' => $request->user()->can('create', InventoryAdjustment::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', InventoryAdjustment::class);

        // Where the stock is counted (phase 31.4): the one picked, else the
        // default. "On hand" on each product is that place's figure, and the
        // form reloads the products when it changes.
        $location = Location::startingAt($request->integer('location') ?: null);

        return Inertia::render('Adjustments/Create', [
            'title' => 'New adjustment',
            'locations' => Location::options(),
            'locationId' => $location?->id,
            'products' => $this->productOptions($location?->id),
            'kinds' => $this->kindOptions(),
            'reasons' => collect(StoreAdjustmentRequest::REASONS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'dates' => [
                'min' => $this->floorDate(),
                'max' => Carbon::today()->toDateString(),
                'today' => Carbon::today()->toDateString(),
            ],
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreAdjustmentRequest $request): RedirectResponse
    {
        $this->authorize('create', InventoryAdjustment::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('adjustments.show', $existing)
                    ->with('info', "Adjustment [{$existing->reference}] was already recorded.");
            }

            throw ValidationException::withMessages([
                'idempotency_key' => "This form already recorded adjustment [{$existing->reference}]. "
                    .'Save again to record what is on screen now as a new one.',
            ]);
        }

        $data = $request->validated();

        try {
            $adjustment = $this->poster->post(
                $data['kind'],
                $data['lines'],
                Carbon::parse($data['adjustment_date']),
                $data['reason'],
                $data['notes'] ?? null,
                $data['idempotency_key'] ?? null,
                // A form with no location at all posts at the default.
                isset($data['location_id']) ? (int) $data['location_id'] : null,
            );
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()->route('adjustments.show', $existing);
            }

            throw $e;
        } catch (InventoryException $e) {
            throw ValidationException::withMessages([
                str_contains($e->getMessage(), 'dated') ? 'adjustment_date' : 'lines' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('adjustments.show', $adjustment)
            ->with('success', "Adjustment [{$adjustment->reference}] was posted.");
    }

    public function show(Request $request, InventoryAdjustment $adjustment): Response
    {
        $this->authorize('view', $adjustment);

        $adjustment->auditView();

        return Inertia::render('Adjustments/Show', array_merge(
            ['title' => $adjustment->reference],
            $this->detail($request, $adjustment),
        ));
    }

    /**
     * The same adjustment, as JSON, for the dialog on the list screen.
     *
     * Both this and `show()` call `detail()`, so the two cannot drift.
     */
    public function preview(Request $request, InventoryAdjustment $adjustment): JsonResponse
    {
        $this->authorize('view', $adjustment);

        $adjustment->auditView();

        return response()->json($this->detail($request, $adjustment));
    }

    /**
     * Everything an adjustment shows, on the page or in the dialog.
     *
     * @return array{adjustment: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, InventoryAdjustment $adjustment): array
    {
        $adjustment->load(['lines.product:id,sku,name', 'postedBy:id,name', 'location:id,name']);

        return [
            'adjustment' => [
                'id' => $adjustment->id,
                'reference' => $adjustment->reference,
                'kind' => $adjustment->kind,
                'kind_label' => $this->kindLabel($adjustment->kind),
                'kind_tone' => $this->kindTone($adjustment->kind),
                'adjustment_date' => $adjustment->adjustment_date?->format('d M Y'),
                // Where the stock was counted in or out (phase 31.4).
                'location' => $adjustment->location?->name,
                'reason' => $this->reasonLabel($adjustment->reason),
                'notes' => $adjustment->notes,
                'total_cost' => $adjustment->total_cost,
                'status' => $adjustment->status->value,
                'status_label' => $adjustment->status->label(),
                'status_tone' => $adjustment->status->tone(),
                'posted_at' => $adjustment->posted_at?->format('d M Y, g:i A'),
                'posted_by' => $adjustment->postedBy?->name,
                'voided_at' => $adjustment->voided_at?->format('d M Y, g:i A'),
                'lines' => $adjustment->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->unit_cost,
                    'total_cost' => $line->total_cost,
                ])->all(),
            ],
            'can' => ['void' => $request->user()->can('void', $adjustment)],
        ];
    }

    public function void(VoidAdjustmentRequest $request, InventoryAdjustment $adjustment): RedirectResponse
    {
        $this->authorize('void', $adjustment);

        try {
            $this->poster->void($adjustment, $request->validated()['reason']);
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Adjustment [{$adjustment->reference}] was voided.");
    }

    /* ------------------------------------------------------------- Helpers */

    protected function kindLabel(string $kind): string
    {
        return match ($kind) {
            InventoryAdjustment::KIND_OPENING => 'Opening stock',
            InventoryAdjustment::KIND_INCREASE => 'Increase',
            InventoryAdjustment::KIND_DECREASE => 'Decrease',
            default => $kind,
        };
    }

    protected function kindTone(string $kind): string
    {
        return match ($kind) {
            InventoryAdjustment::KIND_OPENING => 'neutral',
            InventoryAdjustment::KIND_INCREASE => 'success',
            InventoryAdjustment::KIND_DECREASE => 'warning',
            default => 'neutral',
        };
    }

    protected function reasonLabel(?string $reason): ?string
    {
        return StoreAdjustmentRequest::REASONS[$reason] ?? $reason;
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    protected function kindOptions(): array
    {
        return [
            ['value' => InventoryAdjustment::KIND_INCREASE, 'label' => 'Increase — stock found or added'],
            ['value' => InventoryAdjustment::KIND_DECREASE, 'label' => 'Decrease — stock lost or damaged'],
            // Not hidden: this is how a business enters what it already had
            // on the day it starts using the app.
            ['value' => InventoryAdjustment::KIND_OPENING, 'label' => 'Opening stock — what you already had'],
        ];
    }

    protected function floorDate(): ?string
    {
        $last = InventoryMovement::max('movement_date');

        return $last ? Carbon::parse($last)->toDateString() : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function productOptions(?int $locationId = null): array
    {
        // That place's figures, one grouped read (phase 31.4).
        $there = $locationId ? Reservations::atLocation(null, $locationId) : null;

        return Product::query()
            ->with('unit:id,code,allows_decimals')
            ->where('is_active', true)
            ->orderBy('sku')
            ->get(['id', 'sku', 'name', 'cost', 'on_hand', 'unit_id', ...Product::SEARCH_COLUMNS])
            ->map(fn (Product $p) => [
                'value' => $p->id,
                'label' => "{$p->sku} — {$p->name}",
                // A count is often done with a scanner in hand.
                'keywords' => $p->searchWords(),
                'sku' => $p->sku,
                'cost' => $p->cost,
                'on_hand' => $there === null ? $p->on_hand : Quantity::fromMilli($there[$p->id] ?? 0),
                'unit' => $p->unit?->code,
                // Whether the quantity box takes 2.5 or whole numbers only.
                'decimals' => (bool) $p->unit?->allows_decimals,
            ])
            ->all();
    }

    /**
     * @return array{kind:?string,location_id:?int,reason:?string,status:?string,from:?string,to:?string,posted_by:?int}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'kind' => ['nullable', 'in:opening,increase,decrease'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'reason' => ['nullable', Rule::in(array_keys(StoreAdjustmentRequest::REASONS))],
            'status' => ['nullable', 'in:'.implode(',', DocumentStatus::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'posted_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return [
            'kind' => $validated['kind'] ?? null,
            'location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            'reason' => $validated['reason'] ?? null,
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'posted_by' => isset($validated['posted_by']) ? (int) $validated['posted_by'] : null,
        ];
    }
}
