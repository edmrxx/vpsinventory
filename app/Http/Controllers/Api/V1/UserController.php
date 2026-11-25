<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only user endpoints.
 *
 * Writing is left to the web module in this phase, so the API surface stays
 * small. Adding a write endpoint means reusing the same Form Request and
 * Policy the web controller already uses.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        // The roles are eager loaded, so a page of 25 users costs two
        // queries rather than twenty-six.
        $table = TableQuery::for(User::query()->with('roles:id,name'), $request)
            ->searchable(['name', 'username', 'email', 'roles.name'])
            ->sortable(['name', 'username', 'email', 'is_active', 'created_at'])
            ->defaultSort('name');

        return ApiResponse::paginated($table->paginate(), UserResource::class);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load(['roles:id,name', 'roles.permissions:id,name', 'permissions:id,name']);

        $user->auditView();

        return ApiResponse::resource((new UserResource($user))->withPermissions());
    }
}
