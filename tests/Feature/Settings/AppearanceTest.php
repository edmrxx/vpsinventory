<?php

namespace Tests\Feature\Settings;

use App\Models\AuditTrail;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 35.1 (CR-3): each person chooses a light or a dark screen — Light,
 * Dark or Same as my device — kept on their account; Theme Settings holds
 * the default for anyone who has not chosen. Paper and the sign-in pages
 * are always light.
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app(Settings::class)->flush();
    }

    protected function staff(): User
    {
        return $this->unaudited(fn () => User::factory()->create());
    }

    public function test_a_person_chooses_dark_and_every_page_opens_dark(): void
    {
        $user = $this->staff();

        $this->actingAs($user)
            ->from('/dashboard')
            ->put('/appearance', ['appearance' => 'dark'])
            ->assertRedirect('/dashboard')
            ->assertSessionHasNoErrors();

        $this->assertSame('dark', $user->fresh()->appearance);

        $this->actingAs($user->fresh())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-appearance="dark"', false)
            ->assertSee('data-theme="dark"', false);
    }

    public function test_same_as_my_device_is_left_for_the_browser_to_resolve(): void
    {
        $user = $this->staff();
        $user->forceFill(['appearance' => 'system'])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-appearance="system"', false)
            ->assertDontSee('data-theme=', false)
            // The head script that reads the device before anything is painted.
            ->assertSee("matchMedia('(prefers-color-scheme: dark)')", false);
    }

    public function test_somebody_who_has_not_chosen_follows_the_default_in_theme_settings(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->get('/dashboard')
            ->assertSee('data-theme="light"', false);

        app(Settings::class)->set('theme.appearance', 'dark');

        $this->actingAs($user)->get('/dashboard')
            ->assertSee('data-theme="dark"', false);

        // Their own choice wins over the default.
        $user->forceFill(['appearance' => 'light'])->save();

        $this->actingAs($user->fresh())->get('/dashboard')
            ->assertSee('data-theme="light"', false);
    }

    public function test_choosing_nothing_puts_the_person_back_on_the_default(): void
    {
        $user = $this->staff();
        $user->forceFill(['appearance' => 'dark'])->save();

        $this->actingAs($user)
            ->put('/appearance', ['appearance' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->appearance);
    }

    public function test_an_unknown_or_missing_choice_is_refused(): void
    {
        $user = $this->staff();

        $this->actingAs($user)
            ->put('/appearance', ['appearance' => 'purple'])
            ->assertSessionHasErrors('appearance');

        $this->actingAs($user)
            ->put('/appearance', [])
            ->assertSessionHasErrors('appearance');

        $this->assertNull($user->fresh()->appearance);
    }

    public function test_the_sign_in_page_is_always_light(): void
    {
        app(Settings::class)->set('theme.appearance', 'dark');

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('data-theme=', false)
            ->assertDontSee('data-appearance=', false);

        $this->put('/appearance', ['appearance' => 'dark'])->assertRedirect('/login');
    }

    public function test_the_page_is_told_who_sees_what_so_it_can_follow_a_sign_in_or_out(): void
    {
        app(Settings::class)->set('theme.appearance', 'system');
        $user = $this->staff();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appearance', 'system')
                ->where('auth.user.appearance', null));

        $user->forceFill(['appearance' => 'dark'])->save();

        $this->actingAs($user->fresh())
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appearance', 'dark')
                ->where('auth.user.appearance', 'dark'));
    }

    public function test_a_switch_writes_no_audit_row_and_needs_no_permission(): void
    {
        $user = $this->staff();
        $user->syncPermissions([]);
        $user->syncRoles([]);
        $before = AuditTrail::count();

        $this->actingAs($user)
            ->put('/appearance', ['appearance' => 'dark'])
            ->assertSessionHasNoErrors();

        $this->assertSame('dark', $user->fresh()->appearance);
        $this->assertSame($before, AuditTrail::count());
    }

    public function test_only_the_switch_sets_it_never_a_mass_assignment(): void
    {
        $this->assertNotContains('appearance', (new User)->getFillable());

        $user = $this->staff();
        $user->forceFill(['appearance' => 'dark'])->save();
        $user->update(['name' => 'Renamed', 'appearance' => 'light']);

        $this->assertSame('dark', $user->fresh()->appearance);
    }

    public function test_a_brand_colour_reaches_the_dark_screen_as_its_own_variable(): void
    {
        app(Settings::class)->set('theme.brand_color', '#090321');
        $user = $this->staff();
        $user->forceFill(['appearance' => 'dark'])->save();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('--brand-base:#090321', false)
            ->assertSee('--color-brand-600:#090321', false);
    }
}
