<?php

namespace App\Http\Controllers;

use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Models\Role;
use App\Support\PermissionRegistry;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $table = TableQuery::for(
            Role::query()->withCount(['users', 'permissions']),
            $request
        )
            ->searchable(['name'])
            ->sortable(['name', 'users_count', 'permissions_count', 'created_at'])
            ->defaultSort('name');

        $roles = $table->paginate();

        return Inertia::render('Roles/Index', [
            'title' => 'Roles & Permissions',
            'roles' => $roles->through(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'users_count' => $role->users_count,
                'permissions_count' => $role->permissions_count,
                'is_protected' => $role->isProtected(),
                'is_admin' => $role->isAdmin(),
                'created_at' => $role->created_at?->format('d M Y'),
                'can' => [
                    'update' => $request->user()->can('update', $role),
                    'delete' => $request->user()->can('delete', $role),
                ],
            ]),
            'filters' => $table->state(),
            'can' => [
                'create' => $request->user()->can('create', Role::class),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('Roles/Create', [
            'title' => 'New role',
            'groups' => PermissionRegistry::forForm(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $permissions = $request->input('permissions', []);

        $role = new Role(['name' => $request->input('name'), 'guard_name' => 'web']);
        // Folded into the single Create row the observer writes.
        $role->auditExtra = ['permissions' => $permissions];
        $role->save();

        $role->syncPermissions($permissions);

        return redirect()
            ->route('roles.index')
            ->with('success', "Role [{$role->name}] was created.");
    }

    public function show(Request $request, Role $role): Response
    {
        $this->authorize('view', $role);

        $role->load('permissions:id,name');

        $role->auditView();

        return Inertia::render('Roles/Show', [
            'title' => $role->name,
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'is_protected' => $role->isProtected(),
                'is_admin' => $role->isAdmin(),
                'users_count' => $role->users()->count(),
                'permissions' => $role->permissions->pluck('name')->all(),
                'created_at' => $role->created_at?->format('d M Y, g:i A'),
            ],
            'groups' => PermissionRegistry::forForm(),
            'can' => [
                'update' => $request->user()->can('update', $role),
                'delete' => $request->user()->can('delete', $role),
            ],
        ]);
    }

    public function edit(Role $role): Response
    {
        $this->authorize('update', $role);

        $role->load('permissions:id,name');

        return Inertia::render('Roles/Edit', [
            'title' => "Edit {$role->name}",
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'is_protected' => $role->isProtected(),
                'permissions' => $role->permissions->pluck('name')->all(),
                'users_count' => $role->users()->count(),
            ],
            'groups' => PermissionRegistry::forForm(),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        // Permissions live in a pivot table, so the change is handed to the
        // audit row by hand.
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $after = collect($request->input('permissions', []))->sort()->values()->all();

        $permissionChange = $before === $after
            ? []
            : ['permissions' => ['from' => $before, 'to' => $after]];

        $role->auditExtra = $permissionChange;
        $role->update(['name' => $request->input('name')]);
        $role->syncPermissions($after);

        // Only the permissions changed, so Eloquent wrote no row.
        if (! $role->wasChanged()) {
            $role->auditRelationChange($permissionChange);
        }

        return redirect()
            ->route('roles.index')
            ->with('success', "Role [{$role->name}] was saved.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $name = $role->name;
        $role->delete();

        return redirect()
            ->route('roles.index')
            ->with('success', "Role [{$name}] was deleted.");
    }
}
