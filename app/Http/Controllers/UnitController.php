<?php

namespace App\Http\Controllers;

use App\Http\Requests\Unit\StoreUnitRequest;
use App\Http\Requests\Unit\UpdateUnitRequest;
use App\Models\Unit;
use App\Services\AuditService;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The unit a product is counted in.
 *
 * A short reference list, so it has no detail screen: the table shows
 * everything a unit carries.
 */
class UnitController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Unit::class);

        $filters = $this->validatedFilters($request);

        // The count rides along on every row, so the policy can refuse a
        // delete without asking the database once per row.
        $query = Unit::query()
            ->withCount(['products', 'productUnits', 'saleLines', 'purchaseLines', 'purchaseRequestLines', 'purchaseOrderLines', 'quotationLines', 'salesOrderLines', 'pendingPaymentLines'])
            ->when($filters['status'] !== null, fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->when($filters['measure'] !== null, fn ($q) => $q->where('allows_decimals', $filters['measure'] === 'decimals'));

        $table = TableQuery::for($query, $request)
            ->searchable(['code', 'name'])
            ->sortable(['code', 'name', 'is_active', 'created_at'])
            ->defaultSort('code')
            ->extra($filters);

        return Inertia::render('Units/Index', [
            'title' => 'Units',
            'units' => $table->paginate()->through(fn (Unit $unit) => [
                'id' => $unit->id,
                'code' => $unit->code,
                'name' => $unit->name,
                'allows_decimals' => $unit->allows_decimals,
                'products_count' => $unit->products_count,
                'is_active' => $unit->is_active,
                'created_at' => $unit->created_at?->format('d M Y'),
                'can' => [
                    'update' => $request->user()->can('update', $unit),
                    'delete' => $request->user()->can('delete', $unit),
                    'status' => $request->user()->can('updateStatus', $unit),
                ],
            ]),
            'filters' => $table->state(),
            'can' => ['create' => $request->user()->can('create', Unit::class)],
        ]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        $this->authorize('create', Unit::class);

        $unit = Unit::create($request->validated());

        return back()->with('success', "Unit [{$unit->code}] was created.");
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        $this->authorize('update', $unit);

        $unit->update($request->validated());

        return back()->with('success', "Unit [{$unit->code}] was saved.");
    }

    public function destroy(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorize('delete', $unit);

        $code = $unit->code;
        $unit->delete();

        return redirect()
            ->route('units.index')
            ->with('success', "Unit [{$code}] was deleted.");
    }

    public function updateStatus(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorize('updateStatus', $unit);

        $this->audit->withoutAuditing(
            fn () => $unit->update(['is_active' => ! $unit->is_active])
        );

        $unit->auditStatus($unit->is_active);

        return back()->with(
            'success',
            $unit->is_active
                ? "Unit [{$unit->code}] is active again."
                : "Unit [{$unit->code}] is now inactive."
        );
    }

    /**
     * @return array{status:?string,measure:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,inactive'],
            'measure' => ['nullable', 'in:whole,decimals'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'measure' => $validated['measure'] ?? null,
        ];
    }
}
