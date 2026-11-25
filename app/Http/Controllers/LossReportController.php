<?php

namespace App\Http\Controllers;

use App\Enums\LossReportStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\LossReportException;
use App\Http\Requests\Adjustment\StoreAdjustmentRequest;
use App\Http\Requests\LossReport\LossDecisionRequest;
use App\Http\Requests\LossReport\StoreLossReportRequest;
use App\Models\Location;
use App\Models\LossReport;
use App\Models\LossReportPhoto;
use App\Models\Product;
use App\Services\LossReportDesk;
use App\Support\LossPhoto;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\StaffOptions;
use App\Support\TableQuery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Loss reports (phase 33): a loss is reported with its reason and photos,
 * the goods are held aside, and an approver writes it off — posting a
 * decrease adjustment — or rejects it, freeing the goods.
 *
 * Not a posted document: no edit, no delete, no void. A wrong write-off is
 * corrected the way any posted decrease is, by voiding its adjustment.
 */
class LossReportController extends Controller
{
    public function __construct(protected LossReportDesk $desk) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LossReport::class);

        $filters = $this->validatedFilters($request);

        $query = LossReport::query()
            ->with('location:id,name')
            ->withCount(['lines', 'photos'])
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['reason'], fn ($q, $reason) => $q->where('reason', $reason))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('report_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('report_date', '<=', $to))
            ->when($filters['reported_by'], fn ($q, $id) => $q->where('reported_by', $id));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'notes'])
            ->sortable(['reference', 'report_date', 'status', 'created_at'])
            ->defaultSort('report_date', 'desc')
            ->extra($filters);

        return Inertia::render('LossReports/Index', [
            'title' => 'Loss Reports',
            'reports' => $table->paginate()->through(fn (LossReport $report) => [
                'id' => $report->id,
                'reference' => $report->reference,
                'report_date' => $report->report_date?->format('d M Y'),
                'location' => $report->location?->name,
                'reason' => $report->reasonLabel(),
                'lines_count' => $report->lines_count,
                'photos_count' => $report->photos_count,
                'total_cost' => $report->total_cost,
                'status' => $report->status->value,
                'status_label' => $report->status->label(),
                'status_tone' => $report->status->tone(),
            ]),
            'filters' => $table->state(),
            'options' => [
                'locations' => Location::orderBy('name')->get(['id', 'name'])
                    ->map(fn (Location $l) => ['value' => $l->id, 'label' => $l->name])
                    ->all(),
                'reasons' => $this->reasonOptions(),
                'staff' => StaffOptions::all(),
                'statuses' => LossReportStatus::options(),
            ],
            'can' => ['create' => $request->user()->can('create', LossReport::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', LossReport::class);

        // Where the goods are: the one asked for, else the default. Each
        // product carries what is free there, and the form reloads with
        // `?location=` when the box changes.
        $location = Location::startingAt($request->integer('location') ?: null);

        return Inertia::render('LossReports/Create', [
            'title' => 'Report a loss',
            'locations' => Location::options(),
            'locationId' => $location?->id,
            'products' => $this->productOptions($location?->id),
            'reasons' => $this->reasonOptions(),
            'photographable' => StoreAdjustmentRequest::PHOTOGRAPHABLE,
            'photoRequired' => (bool) settings('losses.photo_required', true),
            'maxPhotos' => LossPhoto::MAX_PER_REPORT,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreLossReportRequest $request): RedirectResponse
    {
        $this->authorize('create', LossReport::class);

        if ($existing = $request->alreadySaved()) {
            return redirect()
                ->route('loss-reports.show', $existing)
                ->with('info', "Loss [{$existing->reference}] was already reported.");
        }

        $data = $request->validated();

        try {
            $report = $this->desk->report(
                (int) $data['location_id'],
                $data['reason'],
                array_map(fn (array $line) => [
                    'product_id' => (int) $line['product_id'],
                    'quantity' => $line['quantity'],
                ], $data['lines']),
                $request->file('photos') ?? [],
                $data['notes'] ?? null,
                $data['idempotency_key'] ?? null,
            );
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()->route('loss-reports.show', $existing);
            }

            throw $e;
        } catch (LossReportException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('loss-reports.show', $report)
            ->with('success', "Loss [{$report->reference}] was reported. The goods are held until it is approved or rejected.");
    }

    public function show(Request $request, LossReport $lossReport): Response
    {
        $this->authorize('view', $lossReport);

        $lossReport->auditView();

        return Inertia::render('LossReports/Show', array_merge(
            ['title' => $lossReport->reference],
            $this->detail($request, $lossReport),
        ));
    }

    /**
     * The same report, as JSON, for the dialog on the list screen. Both this
     * and `show()` call `detail()`, so the two cannot drift.
     */
    public function preview(Request $request, LossReport $lossReport): JsonResponse
    {
        $this->authorize('view', $lossReport);

        $lossReport->auditView();

        return response()->json($this->detail($request, $lossReport));
    }

    /**
     * One evidence photo. The file is found from the report's own photo row
     * — never from a name in the address — and only for somebody who may
     * read the report.
     */
    public function photo(LossReport $lossReport, LossReportPhoto $photo): BinaryFileResponse
    {
        $this->authorize('view', $lossReport);

        abort_unless((int) $photo->loss_report_id === (int) $lossReport->id, 404);

        $file = LossPhoto::file($photo->path);

        abort_if($file === null, 404);

        return response()->file($file, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, LossReport $lossReport): RedirectResponse
    {
        $this->authorize('approve', $lossReport);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        try {
            $report = $this->desk->approve($lossReport, $request->user(), $note);
        } catch (LossReportException|InventoryException $e) {
            throw ValidationException::withMessages(['report' => $e->getMessage()]);
        }

        return back()->with('success', "Loss [{$report->reference}] was written off as {$report->adjustment?->reference}.");
    }

    public function reject(LossDecisionRequest $request, LossReport $lossReport): RedirectResponse
    {
        $this->authorize('reject', $lossReport);

        try {
            $this->desk->reject($lossReport, $request->user(), $request->validated()['reason']);
        } catch (LossReportException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Loss [{$lossReport->reference}] was rejected. The goods are free to sell again.");
    }

    public function cancel(LossDecisionRequest $request, LossReport $lossReport): RedirectResponse
    {
        $this->authorize('cancel', $lossReport);

        try {
            $this->desk->cancel($lossReport, $request->user(), $request->validated()['reason']);
        } catch (LossReportException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Loss [{$lossReport->reference}] was withdrawn. The goods are free to sell again.");
    }

    /**
     * Everything a report shows, on the page or in the dialog.
     *
     * The cost is the batches' own cost, the figure an adjustment shows, so
     * it is not held back behind `sales.view_cost`.
     *
     * @return array{report: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, LossReport $report): array
    {
        $report->load([
            'lines.product:id,sku,name,unit_id', 'lines.product.unit:id,code',
            'photos:id,loss_report_id,original_name',
            'location:id,name', 'reportedBy:id,name', 'decidedBy:id,name', 'cancelledBy:id,name',
            'adjustment:id,reference,status',
        ]);

        $user = $request->user();

        return [
            'report' => [
                'id' => $report->id,
                'reference' => $report->reference,
                'report_date' => $report->report_date?->format('d M Y'),
                'location' => $report->location?->name,
                'reason' => $report->reason,
                'reason_label' => $report->reasonLabel(),
                'notes' => $report->notes,
                'status' => $report->status->value,
                'status_label' => $report->status->label(),
                'status_tone' => $report->status->tone(),
                'reported_by' => $report->reportedBy?->name,
                'reported_at' => $report->created_at?->format('d M Y, g:i A'),
                'decided_at' => $report->decided_at?->format('d M Y, g:i A'),
                'decided_by' => $report->decidedBy?->name,
                'decision_note' => $report->decision_note,
                'cancelled_at' => $report->cancelled_at?->format('d M Y, g:i A'),
                'cancelled_by' => $report->cancelledBy?->name,
                'cancel_reason' => $report->cancel_reason,
                'total_cost' => $report->total_cost,
                'adjustment' => $report->adjustment ? [
                    'id' => $report->adjustment->id,
                    'reference' => $report->adjustment->reference,
                    'status' => $report->adjustment->status->value,
                ] : null,
                'lines' => $report->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'sku' => $line->product?->sku,
                    'name' => $line->product?->name,
                    'unit' => $line->product?->unit?->code,
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->unit_cost,
                    'total_cost' => $line->total_cost,
                ])->all(),
                'photos' => $report->photos->map(fn (LossReportPhoto $photo) => [
                    'id' => $photo->id,
                    'url' => "/loss-reports/{$report->id}/photos/{$photo->id}",
                    'name' => $photo->original_name,
                ])->all(),
            ],
            'can' => [
                'approve' => $user->can('approve', $report),
                'reject' => $user->can('reject', $report),
                'cancel' => $user->can('cancel', $report),
            ],
        ];
    }

    /** @return list<array{value:string,label:string}> */
    protected function reasonOptions(): array
    {
        return array_map(
            fn (string $key) => ['value' => $key, 'label' => StoreAdjustmentRequest::REASONS[$key]],
            StoreAdjustmentRequest::LOSS_REASONS,
        );
    }

    /**
     * The active products, with what is free to report at the location: on
     * hand there, less what orders and other reports hold. Two grouped reads.
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
     * @return array{location_id:?int,reason:?string,status:?string,from:?string,to:?string,reported_by:?int}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'reason' => ['nullable', Rule::in(StoreAdjustmentRequest::LOSS_REASONS)],
            'status' => ['nullable', Rule::in(LossReportStatus::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reported_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return [
            'location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            'reason' => $validated['reason'] ?? null,
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'reported_by' => isset($validated['reported_by']) ? (int) $validated['reported_by'] : null,
        ];
    }
}
