<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Support\ApiResponse;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only role endpoints.
 */
class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $table = TableQuery::for(
            Role::query()->withCount(['users', 'permissions']),
            $request
        )
            ->searchable(['name'])
            ->sortable(['name', 'users_count', 'permissions_count', 'created_at'])
            ->defaultSort('name');

        return ApiResponse::paginated($table->paginate(), RoleResource::class);
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        $role->load('permissions:id,name')->loadCount('users');

        $role->auditView();

        return ApiResponse::resource(new RoleResource($role));
    }
}
