<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\LocationType;
use App\Http\Requests\Location\StoreLocationRequest;
use App\Http\Requests\Location\UpdateLocationRequest;
use App\Models\Location;
use App\Models\LocationStock;
use App\Services\AuditService;
use App\Support\TableQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The places that hold stock: the store and the warehouse (phase 31.1).
 *
 * A short master list shaped like Departments: no detail screen, the form in
 * a dialog on the list. Since phase 31.2 each one holds its own stock, one is
 * the default every document posts at, and `LocationPolicy` keeps a location
 * with stock or history from being switched off or deleted.
 */
class LocationController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Location::class);

        $filters = $this->validatedFilters($request);

        // Whether each row holds stock or has history rides along, so the
        // policy refuses without a query per row.
        $query = Location::query()
            ->withUsage()
            ->when($filters['type'], fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] !== null, fn ($q) => $q->where('is_active', $filters['status'] === 'active'));

        // Read once, so asking each row "may this become the default?" costs
        // nothing more.
        $current = Location::query()->withUsage()->where('is_default', true)->orderBy('id')->first();

        $table = TableQuery::for($query, $request)
            ->searchable(['name', 'address', 'description'])
            ->sortable(['name', 'type', 'is_active', 'created_at'])
            ->defaultSort('name')
            ->extra($filters);

        return Inertia::render('Locations/Index', [
            'title' => 'Locations',
            'locations' => $table->paginate()->through(fn (Location $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'type' => $location->type->value,
                'type_label' => $location->type->label(),
                'is_default' => $location->is_default,
                'holds_stock' => $location->holdsStock(),
                'address' => $location->address,
                'description' => $location->description,
                'is_active' => $location->is_active,
                'created_at' => $location->created_at?->format('d M Y'),
                'can' => [
                    'update' => $request->user()->can('update', $location),
                    'delete' => $request->user()->can('delete', $location),
                    'status' => $request->user()->can('updateStatus', $location),
                    'makeDefault' => $request->user()->can('makeDefault', [$location, $current]),
                ],
            ]),
            'filters' => $table->state(),
            'types' => LocationType::options(),
            'can' => ['create' => $request->user()->can('create', Location::class)],
        ]);
    }

    public function store(StoreLocationRequest $request): RedirectResponse
    {
        $this->authorize('create', Location::class);

        $location = DB::transaction(function () use ($request) {
            $location = Location::create($request->validated());

            // The first location there is becomes the default, so a fresh
            // set of books always has somewhere to post (phase 31.2).
            if ($location->is_active && ! Location::where('is_default', true)->lockForUpdate()->exists()) {
                $location->forceFill(['is_default' => true])->saveQuietly();
            }

            return $location;
        });

        return back()->with('success', "Location [{$location->name}] was created.");
    }

    public function update(UpdateLocationRequest $request, Location $location): RedirectResponse
    {
        $this->authorize('update', $location);

        $location->update($request->validated());

        return back()->with('success', "Location [{$location->name}] was saved.");
    }

    public function destroy(Request $request, Location $location): RedirectResponse
    {
        $this->authorize('delete', $location);

        $name = $location->name;
        $location->delete();

        return redirect()
            ->route('locations.index')
            ->with('success', "Location [{$name}] was deleted.");
    }

    /**
     * Make this the location every document posts at (phase 31.2). The old
     * default is cleared in the same transaction, so there is always exactly
     * one.
     */
    public function makeDefault(Request $request, Location $location): RedirectResponse
    {
        $this->authorize('makeDefault', $location);

        DB::transaction(function () use ($location) {
            $old = Location::where('is_default', true)->lockForUpdate()->get();

            // Asked again under the lock: the policy looked before anything
            // was held, and a delivery may have posted at the old default
            // since. The ledger's own locking read waits on this one.
            $stocked = LocationStock::whereIn('location_id', $old->pluck('id'))
                ->where('on_hand', '!=', 0)
                ->lockForUpdate()
                ->exists();

            if ($stocked) {
                throw new AuthorizationException(
                    'The default location took in stock a moment ago, so it cannot change now.'
                );
            }

            $old->each(fn (Location $o) => $o->forceFill(['is_default' => false])->saveQuietly());

            $location->forceFill(['is_default' => true])->saveQuietly();
        });

        $location->auditCustom(AuditAction::Update, "Made location [{$location->name}] the default.");

        return back()->with('success', "{$location->name} is now the default location.");
    }

    public function updateStatus(Request $request, Location $location): RedirectResponse
    {
        $this->authorize('updateStatus', $location);

        $this->audit->withoutAuditing(
            fn () => $location->update(['is_active' => ! $location->is_active])
        );

        $location->auditStatus($location->is_active);

        return back()->with(
            'success',
            $location->is_active
                ? "Location [{$location->name}] is active again."
                : "Location [{$location->name}] is now inactive."
        );
    }

    /**
     * @return array{type:?string,status:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(LocationType::values())],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        return [
            'type' => $validated['type'] ?? null,
            'status' => $validated['status'] ?? null,
        ];
    }
}
