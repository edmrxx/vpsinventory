<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    /**
     * The list screen, served to the DataTable component.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $table = TableQuery::for(User::query()->with('roles:id,name'), $request)
            ->searchable(['name', 'username', 'email', 'roles.name'])
            ->sortable(['name', 'username', 'email', 'is_active', 'created_at'])
            ->defaultSort('name');

        $users = $table->paginate();

        return Inertia::render('Users/Index', [
            'title' => 'Users',
            'users' => $users->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->roles->pluck('name')->all(),
                'created_at' => $user->created_at?->format('d M Y'),
                // The row's buttons follow what this user may do to that row.
                'can' => [
                    'update' => $request->user()->can('update', $user),
                    'delete' => $request->user()->can('delete', $user),
                    'status' => $request->user()->can('updateStatus', $user),
                ],
            ]),
            'filters' => $table->state(),
            'can' => [
                'create' => $request->user()->can('create', User::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Users/Create', [
            'title' => 'New user',
            'roles' => $this->roleOptions(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $roles = $request->input('roles', []);

        $user = new User($request->safe()->except('roles'));
        // Folded into the single Create row the observer writes.
        $user->auditExtra = ['roles' => $roles];
        $user->save();

        $user->syncRoles($roles);

        return redirect()
            ->route('users.index')
            ->with('success', "User [{$user->username}] was created.");
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorize('view', $user);

        $user->load('roles:id,name');

        $user->auditView();

        return Inertia::render('Users/Show', [
            'title' => $user->name,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'initials' => $user->initials(),
                'roles' => $user->roles->pluck('name')->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
                'created_at' => $user->created_at?->format('d M Y, g:i A'),
                'updated_at' => $user->updated_at?->format('d M Y, g:i A'),
            ],
            'can' => [
                'update' => $request->user()->can('update', $user),
                'delete' => $request->user()->can('delete', $user),
            ],
        ]);
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);

        $user->load('roles:id,name');

        return Inertia::render('Users/Edit', [
            'title' => "Edit {$user->name}",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->roles->pluck('name')->all(),
            ],
            'roles' => $this->roleOptions(),
            'isSelf' => $request->user()->is($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->safe()->except(['roles', 'password']);

        if ($request->filled('password')) {
            $data['password'] = $request->input('password');
        }

        // Roles live in a pivot table, so the change is handed to the audit
        // row by hand. That keeps one edit to one line in the trail.
        $before = $user->roles->pluck('name')->sort()->values()->all();
        $after = collect($request->input('roles', []))->sort()->values()->all();

        $roleChange = $before === $after
            ? []
            : ['roles' => ['from' => $before, 'to' => $after]];

        $user->auditExtra = $roleChange;
        $user->update($data);
        $user->syncRoles($after);

        // Only the roles changed, so Eloquent saw nothing and wrote no row.
        if (! $user->wasChanged()) {
            $user->auditRelationChange($roleChange);
        }

        return redirect()
            ->route('users.index')
            ->with('success', "User [{$user->username}] was saved.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $username = $user->username;
        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('success', "User [{$username}] was deleted.");
    }

    /**
     * Turn an account on or off. This is the status management step of the
     * CRUD standard, kept apart from the edit form so one click is enough.
     */
    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $this->authorize('updateStatus', $user);

        // The save itself is not recorded as an Update, because this action
        // has its own two words in the trail: Activate and Deactivate.
        $this->audit->withoutAuditing(
            fn () => $user->update(['is_active' => ! $user->is_active])
        );

        $user->auditStatus($user->is_active);

        return back()->with(
            'success',
            $user->is_active
                ? "User [{$user->username}] is active again."
                : "User [{$user->username}] is now inactive."
        );
    }

    /**
     * Role names for the pick list on the user form.
     *
     * @return list<array{name:string,label:string,users_count:int}>
     */
    protected function roleOptions(): array
    {
        return Role::query()
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => $role->name,
                'users_count' => $role->users_count,
            ])
            ->all();
    }
}
