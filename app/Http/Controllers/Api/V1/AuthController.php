<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ApiLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuditService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    /**
     * Exchange a username and password for a token.
     *
     * The token carries the user's permissions as abilities, so a client can
     * check what it may do without a second call, and a stolen token can be
     * narrowed later without touching the roles.
     */
    public function login(ApiLoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

        $user->load(['roles:id,name', 'roles.permissions:id,name', 'permissions:id,name']);

        $abilities = $user->getAllPermissions()->pluck('name')->values()->all();

        // One token per device name, so signing in again on the same phone
        // does not leave the old token alive.
        $user->tokens()->where('name', $request->deviceName())->delete();

        // Sanctum's own expiry is left null in config, so the lifetime is
        // set per token from the settings page instead. A token that never
        // expires is a password that can never be changed.
        $token = $user->createToken(
            $request->deviceName(),
            $abilities,
            now()->addDays(max(1, (int) settings('api.token_lifetime_days'))),
        );

        $this->audit->logLogin($user);

        return ApiResponse::success([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
            'user' => (new UserResource($user))->withPermissions()->resolve($request),
        ], 'Signed in.');
    }

    /**
     * The signed-in user, with roles and permissions.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['roles:id,name', 'roles.permissions:id,name', 'permissions:id,name']);

        return ApiResponse::resource((new UserResource($user))->withPermissions());
    }

    /**
     * Throw away the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->audit->logLogout($user);

        $request->user()->currentAccessToken()->delete();

        return ApiResponse::noContent('Signed out.');
    }

    /**
     * Throw away every token this user holds, on every device.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->audit->logLogout($user);

        $count = $user->tokens()->count();
        $user->tokens()->delete();

        return ApiResponse::success(
            ['revoked' => $count],
            'Signed out on every device.'
        );
    }
}
