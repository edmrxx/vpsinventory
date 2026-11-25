<?php

namespace App\Http\Controllers;

use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\AuditService;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a product is grouped under.
 *
 * A short reference list, so it has no detail screen: the table shows
 * everything a category holds.
 */
class CategoryController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Category::class);

        $filters = $this->validatedFilters($request);

        // The count rides along on every row, so the policy can refuse a
        // delete without asking the database once per row.
        $query = Category::query()
            ->withCount('products')
            ->when($filters['status'] !== null, fn ($q) => $q->where('is_active', $filters['status'] === 'active'));

        $table = TableQuery::for($query, $request)
            ->searchable(['name', 'description'])
            ->sortable(['name', 'is_active', 'created_at'])
            ->defaultSort('name')
            ->extra($filters);

        return Inertia::render('Categories/Index', [
            'title' => 'Categories',
            'categories' => $table->paginate()->through(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'products_count' => $category->products_count,
                'is_active' => $category->is_active,
                'created_at' => $category->created_at?->format('d M Y'),
                'can' => [
                    'update' => $request->user()->can('update', $category),
                    'delete' => $request->user()->can('delete', $category),
                    'status' => $request->user()->can('updateStatus', $category),
                ],
            ]),
            'filters' => $table->state(),
            'can' => ['create' => $request->user()->can('create', Category::class)],
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $category = Category::create($request->validated());

        return back()->with('success', "Category [{$category->name}] was created.");
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $category->update($request->validated());

        return back()->with('success', "Category [{$category->name}] was saved.");
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $name = $category->name;
        $category->delete();

        return redirect()
            ->route('categories.index')
            ->with('success', "Category [{$name}] was deleted.");
    }

    public function updateStatus(Request $request, Category $category): RedirectResponse
    {
        $this->authorize('updateStatus', $category);

        $this->audit->withoutAuditing(
            fn () => $category->update(['is_active' => ! $category->is_active])
        );

        $category->auditStatus($category->is_active);

        return back()->with(
            'success',
            $category->is_active
                ? "Category [{$category->name}] is active again."
                : "Category [{$category->name}] is now inactive."
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
