<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user, as the API sends it.
 *
 * The password and the remember token are never here.
 *
 * Permissions are left out unless an endpoint asks for them with
 * `withPermissions()`, because working them out reads the role pivot. On a
 * list of 40 users that was 40 extra queries, so a list omits them and the
 * single-record endpoints ask for them and eager load what they need.
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    protected bool $withPermissions = false;

    /**
     * Include the flattened permission list in this reply.
     *
     * The caller must eager load `roles.permissions` first, or this costs a
     * query.
     */
    public function withPermissions(bool $value = true): static
    {
        $this->withPermissions = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'initials' => $this->initials(),
            'is_active' => (bool) $this->is_active,
            'roles' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->pluck('name')->values()->all()
            ),
            'permissions' => $this->when(
                $this->withPermissions,
                fn () => $this->getAllPermissions()->pluck('name')->sort()->values()->all()
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
