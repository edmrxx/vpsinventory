<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PermissionRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_the_root_url_points_at_the_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_logged_in_users_can_see_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('title', 'Dashboard')
            );
    }

    public function test_the_shared_props_carry_the_signed_in_user(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->admin()->create([
            'name' => 'Ada Lovelace',
            'username' => 'ada',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.username', 'ada')
                ->where('auth.user.roles', ['Admin'])
                ->where('auth.user.initials', 'AL')
                // Every permission except the opt-in ones, which say where
                // an account works rather than what it may do and are never
                // granted by the Admin wildcard.
                ->has(
                    'auth.user.permissions',
                    count(PermissionRegistry::names()) - count(PermissionRegistry::optIn()),
                )
                ->missing('auth.user.password')
            );
    }
}
