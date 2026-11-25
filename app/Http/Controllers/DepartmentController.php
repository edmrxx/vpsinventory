<?php

namespace App\Http\Controllers;

use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Models\Department;
use App\Services\AuditService;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The parts of the business that ask to buy goods (phase 24.1).
 *
 * A short master list shaped like Categories: no detail screen, the form in
 * a dialog on the list. A purchase request picks one; one that a request
 * names is switched off, never deleted.
 */
class DepartmentController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Department::class);

        $filters = $this->validatedFilters($request);

        // The count rides along on every row, so the policy can refuse a
        // delete without asking the database once per row.
        $query = Department::query()
            ->withCount('purchaseRequests')
            ->when($filters['status'] !== null, fn ($q) => $q->where('is_active', $filters['status'] === 'active'));

        $table = TableQuery::for($query, $request)
            ->searchable(['name', 'description'])
            ->sortable(['name', 'is_active', 'created_at'])
            ->defaultSort('name')
            ->extra($filters);

        return Inertia::render('Departments/Index', [
            'title' => 'Departments',
            'departments' => $table->paginate()->through(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'description' => $department->description,
                'requests_count' => $department->purchase_requests_count,
                'is_active' => $department->is_active,
                'created_at' => $department->created_at?->format('d M Y'),
                'can' => [
                    'update' => $request->user()->can('update', $department),
                    'delete' => $request->user()->can('delete', $department),
                    'status' => $request->user()->can('updateStatus', $department),
                ],
            ]),
            'filters' => $table->state(),
            'can' => ['create' => $request->user()->can('create', Department::class)],
        ]);
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Department::class);

        $department = Department::create($request->validated());

        return back()->with('success', "Department [{$department->name}] was created.");
    }

    public function update(UpdateDepartmentRequest $request, Department $department): RedirectResponse
    {
        $this->authorize('update', $department);

        $department->update($request->validated());

        return back()->with('success', "Department [{$department->name}] was saved.");
    }

    public function destroy(Request $request, Department $department): RedirectResponse
    {
        $this->authorize('delete', $department);

        $name = $department->name;
        $department->delete();

        return redirect()
            ->route('departments.index')
            ->with('success', "Department [{$name}] was deleted.");
    }

    public function updateStatus(Request $request, Department $department): RedirectResponse
    {
        $this->authorize('updateStatus', $department);

        $this->audit->withoutAuditing(
            fn () => $department->update(['is_active' => ! $department->is_active])
        );

        $department->auditStatus($department->is_active);

        return back()->with(
            'success',
            $department->is_active
                ? "Department [{$department->name}] is active again."
                : "Department [{$department->name}] is now inactive."
        );
    }

    /**
     * @return array{status:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        return ['status' => $validated['status'] ?? null];
    }
}
