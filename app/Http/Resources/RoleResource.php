<?php

namespace App\Http\Resources;

use App\Support\PermissionRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role, as the API sends it.
 *
 * @mixin \App\Models\Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_protected' => $this->isProtected(),
            'is_admin' => $this->isAdmin(),
            'users_count' => $this->whenCounted('users'),
            'permissions_count' => $this->whenCounted('permissions'),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name')->sort()->values()->all()
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
