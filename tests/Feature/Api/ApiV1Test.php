<?php

namespace Tests\Feature\Api;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function admin(array $attributes = []): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create($attributes));
    }

    /** A token for a user, with their real permissions as abilities. */
    protected function tokenFor(User $user): string
    {
        return $user->createToken(
            'test',
            $user->getAllPermissions()->pluck('name')->all()
        )->plainTextToken;
    }

    /* --------------------------------------------------------------- Login */

    public function test_a_username_and_password_return_a_token(): void
    {
        $this->unaudited(fn () => User::factory()->withRole('Staff')->create([
            'username' => 'apiuser',
            'password' => 'api-pass-123',
        ]));

        $response = $this->postJson('/api/v1/login', [
            'username' => 'ApiUser',
            'password' => 'api-pass-123',
            'device_name' => 'iPhone 15',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'token_type',
                    'abilities',
                    'user' => ['id', 'name', 'username', 'roles', 'permissions'],
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.username', 'apiuser')
            ->assertJsonPath('data.user.roles', ['Staff']);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'iPhone 15']);
    }

    public function test_the_token_carries_the_permissions_as_abilities(): void
    {
        $this->unaudited(fn () => User::factory()->withRole('Staff')->create([
            'username' => 'apiuser',
            'password' => 'api-pass-123',
        ]));

        $abilities = $this->postJson('/api/v1/login', [
            'username' => 'apiuser',
            'password' => 'api-pass-123',
        ])->json('data.abilities');

        $this->assertEqualsCanonicalizing(PermissionRegistry::forRole('Staff'), $abilities);
    }

    public function test_signing_in_again_on_the_same_device_replaces_the_token(): void
    {
        $user = $this->unaudited(fn () => User::factory()->create([
            'username' => 'apiuser',
            'password' => 'api-pass-123',
        ]));

        $credentials = [
            'username' => 'apiuser',
            'password' => 'api-pass-123',
            'device_name' => 'iPhone 15',
        ];

        $first = $this->postJson('/api/v1/login', $credentials)->json('data.token');
        $second = $this->postJson('/api/v1/login', $credentials)->json('data.token');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $user->tokens()->count(), 'The old token must be gone.');

        $this->forgetSignedInUser();
        $this->withToken($first)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_wrong_password_is_refused_in_the_standard_shape(): void
    {
        $this->unaudited(fn () => User::factory()->create([
            'username' => 'apiuser',
            'password' => 'api-pass-123',
        ]));

        $this->postJson('/api/v1/login', [
            'username' => 'apiuser',
            'password' => 'wrong-pass',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The details given are not valid.')
            ->assertJsonStructure(['success', 'message', 'errors' => ['username']]);
    }

    public function test_missing_fields_are_refused(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['username', 'password']]);
    }

    public function test_a_login_is_written_to_the_audit_trail(): void
    {
        $this->unaudited(fn () => User::factory()->create([
            'username' => 'apiuser',
            'password' => 'api-pass-123',
        ]));

        $this->postJson('/api/v1/login', [
            'username' => 'apiuser',
            'password' => 'api-pass-123',
        ])->assertOk();

        $trail = AuditTrail::latest('id')->first();

        $this->assertSame(AuditAction::Login, $trail->action);
        $this->assertSame('apiuser', $trail->username);
    }

    /* ------------------------------------------------------------- Current */

    public function test_me_returns_the_signed_in_user(): void
    {
        $admin = $this->admin(['name' => 'Ada Lovelace', 'username' => 'ada']);

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.username', 'ada')
            ->assertJsonPath('data.initials', 'AL')
            ->assertJsonPath('data.roles', ['Admin'])
            ->assertJsonCount(
                count(PermissionRegistry::names()) - count(PermissionRegistry::optIn()),
                'data.permissions',
            );
    }

    public function test_me_needs_a_token(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This request needs a valid token.')
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_a_rubbish_token_is_refused(): void
    {
        $this->withToken('not-a-real-token')
            ->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    /* -------------------------------------------------------------- Logout */

    public function test_logout_throws_away_the_current_token_only(): void
    {
        $user = $this->admin();

        $phone = $user->createToken('phone')->plainTextToken;
        $user->createToken('tablet');

        $this->withToken($phone)
            ->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('tablet', $user->tokens()->first()->name);
    }

    public function test_logout_all_throws_away_every_token(): void
    {
        $user = $this->admin();

        $phone = $user->createToken('phone')->plainTextToken;
        $user->createToken('tablet');
        $user->createToken('laptop');

        $this->withToken($phone)
            ->postJson('/api/v1/logout-all')
            ->assertOk()
            ->assertJsonPath('data.revoked', 3);

        $this->assertSame(0, $user->tokens()->count());
    }

    /* --------------------------------------------------------------- Users */

    public function test_the_user_list_is_paginated_in_the_standard_shape(): void
    {
        $admin = $this->admin();
        $this->unaudited(fn () => User::factory()->count(30)->withRole('Staff')->create());

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [['id', 'name', 'username', 'is_active', 'roles']],
                'meta' => ['current_page', 'per_page', 'last_page', 'total', 'from', 'to'],
            ])
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.total', 31)
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_the_user_list_can_be_searched_and_sorted(): void
    {
        $admin = $this->admin(['name' => 'Zed Admin']);
        $this->unaudited(fn () => User::factory()->create([
            'name' => 'Maria Santos',
            'username' => 'msantos',
        ]));

        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->getJson('/api/v1/users?search=maria')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'msantos');

        $this->withToken($token)
            ->getJson('/api/v1/users?sort=name&direction=desc')
            ->assertJsonPath('data.0.name', 'Zed Admin');
    }

    public function test_the_user_list_needs_the_view_permission(): void
    {
        $user = $this->unaudited(fn () => User::factory()->create());

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/users')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_one_user_can_be_fetched(): void
    {
        $admin = $this->admin();
        $target = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());

        $this->withToken($this->tokenFor($admin))
            ->getJson("/api/v1/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.roles', ['Staff'])
            ->assertJsonStructure(['data' => ['permissions']]);
    }

    public function test_fetching_one_user_is_written_to_the_audit_trail(): void
    {
        $admin = $this->admin();
        $target = $this->unaudited(fn () => User::factory()->create(['username' => 'target']));

        $this->withToken($this->tokenFor($admin))
            ->getJson("/api/v1/users/{$target->id}")
            ->assertOk();

        $trail = AuditTrail::latest('id')->first();

        $this->assertSame(AuditAction::View, $trail->action);
        $this->assertSame('Users', $trail->module);
        $this->assertStringContainsString('target', $trail->description);
    }

    public function test_a_missing_user_answers_in_the_standard_shape(): void
    {
        $admin = $this->admin();

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/users/999999')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'That record does not exist.');
    }

    /* --------------------------------------------------------------- Roles */

    public function test_the_role_list_carries_its_counts(): void
    {
        $admin = $this->admin();

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Admin')
            ->assertJsonPath('data.0.is_protected', true)
            ->assertJsonPath('data.0.is_admin', true)
            ->assertJsonPath('data.0.users_count', 1)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_one_role_carries_its_permissions(): void
    {
        $admin = $this->admin();
        $staff = Role::where('name', 'Staff')->first();

        $this->withToken($this->tokenFor($admin))
            ->getJson("/api/v1/roles/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Staff')
            ->assertJsonPath('data.permissions', collect(PermissionRegistry::forRole('Staff'))
                ->sort()->values()->all());
    }

    public function test_the_role_list_needs_the_view_permission(): void
    {
        $user = $this->unaudited(
            fn () => User::factory()->withPermissions('users.view')->create()
        );

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/roles')
            ->assertStatus(403);
    }

    /* ------------------------------------------------------------ The shape */

    public function test_an_unknown_endpoint_answers_in_json_not_html(): void
    {
        $this->getJson('/api/v1/nonsense')
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This endpoint does not exist.');
    }

    public function test_the_wrong_method_answers_in_the_standard_shape(): void
    {
        $admin = $this->admin();

        $this->withToken($this->tokenFor($admin))
            ->postJson('/api/v1/me')
            ->assertStatus(405)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_the_api_writes_nothing_to_the_session(): void
    {
        $admin = $this->admin();

        $response = $this->withToken($this->tokenFor($admin))->getJson('/api/v1/me');

        $response->assertOk();

        // Stateless: no session cookie comes back.
        $this->assertNull(
            $response->headers->getCookies()[0] ?? null,
            'The API must not set a cookie.'
        );
    }

    public function test_every_reply_says_whether_it_worked(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin);

        $replies = [
            $this->withToken($token)->getJson('/api/v1/me'),
            $this->withToken($token)->getJson('/api/v1/users'),
            $this->withToken($token)->getJson('/api/v1/roles'),
            $this->getJson('/api/v1/me'),
            $this->getJson('/api/v1/nonsense'),
            $this->postJson('/api/v1/login', []),
        ];

        foreach ($replies as $reply) {
            $body = $reply->json();

            $this->assertArrayHasKey('success', $body);
            $this->assertIsBool($body['success']);
            $this->assertArrayHasKey('message', $body);
            $this->assertTrue(
                array_key_exists($body['success'] ? 'data' : 'errors', $body),
                'A success carries data; a failure carries errors.'
            );
        }
    }
}
