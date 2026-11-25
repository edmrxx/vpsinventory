<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DepartmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('departments.view');
    }

    public function view(User $actor, Department $department): bool
    {
        return $actor->can('departments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('departments.create');
    }

    public function update(User $actor, Department $department): bool
    {
        return $actor->can('departments.update');
    }

    /**
     * A department named on a purchase request stays, so the request keeps
     * reading correctly. Switch it off instead.
     */
    public function delete(User $actor, Department $department): Response
    {
        if (! $actor->can('departments.delete')) {
            return Response::deny('You may not delete departments.');
        }

        $requests = $this->requestCount($department);

        if ($requests > 0) {
            return Response::deny(
                "This department is named on {$requests} purchase ".($requests === 1 ? 'request' : 'requests')
                    .'. Switch it off instead, so they keep reading correctly.'
            );
        }

        return Response::allow();
    }

    public function updateStatus(User $actor, Department $department): bool
    {
        return $actor->can('departments.status');
    }

    /**
     * The list screen loads the count with `withCount`, so a page of rows
     * asks nothing extra. Only a single-record route falls back to a query.
     */
    protected function requestCount(Department $department): int
    {
        return (int) ($department->purchase_requests_count ?? $department->purchaseRequests()->count());
    }
}
