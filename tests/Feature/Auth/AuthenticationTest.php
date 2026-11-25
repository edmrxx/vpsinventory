<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    /* -------------------------------------------------------------- The kiosk */

    public function test_the_kiosk_screen_can_be_rendered(): void
    {
        $this->get('/kiosk')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Kiosk'));
    }

    /**
     * The kiosk is a screen, not a second way in.
     *
     * It posts to the very same `/login` route, so the validation, the
     * throttle and the session handling are the ones already tested above.
     * A second authentication path would be a second thing to get wrong,
     * and the one nobody remembers to check when a rule changes.
     *
     * This is the test that keeps it that way: if somebody ever adds a
     * `POST /kiosk`, it fails.
     */
    public function test_the_kiosk_has_no_sign_in_route_of_its_own(): void
    {
        $writes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'kiosk'))
            ->filter(fn ($route) => array_intersect(
                $route->methods(),
                ['POST', 'PUT', 'PATCH', 'DELETE'],
            ) !== [])
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(
            [],
            $writes,
            "The kiosk must sign in through /login, not a route of its own:\n  "
                .implode("\n  ", $writes)
        );
    }

    /**
     * Only an account holding `kiosk.use` may sign in from the tablet.
     *
     * A permission rather than a role name, so a second role can be given
     * the tablet later by ticking a box instead of changing code.
     */
    public function test_only_an_account_with_the_kiosk_permission_may_use_it(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $encoder = $this->unaudited(fn () => User::factory()->withPermissions('kiosk.use')->create([
            'username' => 'encoder',
            'password' => 'tablet-pass-123',
        ]));

        $this->from('/kiosk')
            ->post('/login', [
                'username' => 'encoder',
                'password' => 'tablet-pass-123',
                'from' => 'kiosk',
            ])
            // The till, not the dashboard: somebody who signed in at the
            // tablet came to serve a customer.
            ->assertRedirect(route('pos'));

        $this->assertAuthenticatedAs($encoder);
    }

    /**
     * An administrator is turned away at the tablet.
     *
     * This is the whole point: an account that can do everything should
     * not be the one left signed in on a tablet on a bench.
     */
    public function test_an_account_without_the_permission_is_refused_at_the_kiosk(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->unaudited(fn () => User::factory()->admin()->create([
            'username' => 'theboss',
            'password' => 'desk-pass-123',
        ]));

        $this->from('/kiosk')
            ->post('/login', [
                'username' => 'theboss',
                'password' => 'desk-pass-123',
                'from' => 'kiosk',
            ])
            ->assertRedirect('/kiosk')
            ->assertSessionHasErrors('username');

        // Turned away, and not left half signed in.
        $this->assertGuest();
    }

    /**
     * And the ordinary screen still works for that same account.
     *
     * The kiosk rule narrows one screen. It must not lock anybody out of
     * the system.
     */
    public function test_the_same_account_still_signs_in_on_the_ordinary_screen(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $boss = $this->unaudited(fn () => User::factory()->admin()->create([
            'username' => 'theboss',
            'password' => 'desk-pass-123',
        ]));

        $this->from('/login')
            ->post('/login', [
                'username' => 'theboss',
                'password' => 'desk-pass-123',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($boss);
    }

    /**
     * A wrong password at the kiosk reads exactly as it always did.
     *
     * The kiosk check runs only after the password has been accepted, so
     * it can never become a way of finding out which accounts exist.
     */
    public function test_a_wrong_password_at_the_kiosk_gives_nothing_away(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->unaudited(fn () => User::factory()->admin()->create([
            'username' => 'theboss',
            'password' => 'desk-pass-123',
        ]));

        $this->from('/kiosk')
            ->post('/login', [
                'username' => 'theboss',
                'password' => 'wrong-one',
                'from' => 'kiosk',
            ])
            ->assertSessionHasErrors(['username' => __('auth.failed')]);

        $this->assertGuest();
    }

    /** Anything else in that field is refused outright. */
    public function test_the_screen_field_only_accepts_the_kiosk(): void
    {
        $this->post('/login', [
            'username' => 'somebody',
            'password' => 'something',
            'from' => 'somewhere-else',
        ])->assertSessionHasErrors('from');
    }

    /** A user signs in from the kiosk exactly as they would anywhere else. */
    public function test_a_user_can_sign_in_from_the_kiosk(): void
    {
        $user = $this->unaudited(fn () => User::factory()->create([
            'username' => 'floorstaff',
            'password' => 'tablet-pass-123',
        ]));

        $this->from('/kiosk')
            ->post('/login', [
                'username' => 'floorstaff',
                'password' => 'tablet-pass-123',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /**
     * A wrong password on the kiosk is refused the same way.
     *
     * Sent back to the kiosk, not to the ordinary screen: somebody at the
     * tablet should not be handed a form built for a desk.
     */
    public function test_a_wrong_password_sends_the_kiosk_back_to_itself(): void
    {
        $this->unaudited(fn () => User::factory()->create([
            'username' => 'floorstaff',
            'password' => 'tablet-pass-123',
        ]));

        $this->from('/kiosk')
            ->post('/login', [
                'username' => 'floorstaff',
                'password' => 'wrong-one',
            ])
            ->assertRedirect('/kiosk')
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    /**
     * The kiosk never keeps a shared tablet signed in.
     *
     * The screen offers no "remember me", and the browser must not be able
     * to ask for one by hand: a shared device that stays signed in is an
     * unlocked door.
     */
    public function test_the_kiosk_screen_offers_no_remember_me(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Auth/Kiosk.vue'));

        // The field and the checkbox, not the word: the comment above them
        // explains why they are absent and would match a cruder check.
        $this->assertStringNotContainsString('form.remember', $source);
        $this->assertStringNotContainsString('name="remember"', $source);

        // And the ordinary screen still has both, so this is a difference
        // between the two rather than a feature quietly lost.
        $ordinary = file_get_contents(resource_path('js/Pages/Auth/Login.vue'));

        $this->assertStringContainsString('form.remember', $ordinary);
    }

    /** A signed-in user has no business on the kiosk screen either. */
    public function test_the_kiosk_is_closed_to_anybody_already_signed_in(): void
    {
        $user = $this->unaudited(fn () => User::factory()->create());

        $this->actingAs($user)
            ->get('/kiosk')
            ->assertRedirect(route('dashboard'));
    }

    public function test_users_can_authenticate_with_their_username(): void
    {
        $user = User::factory()->create([
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ]);

        $response = $this->post('/login', [
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));
    }

    public function test_users_cannot_authenticate_with_an_email_address(): void
    {
        User::factory()->create([
            'username' => 'jdoe',
            'email' => 'jdoe@example.test',
            'password' => 'secret-pass',
        ]);

        $this->post('/login', [
            'username' => 'jdoe@example.test',
            'password' => 'secret-pass',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_users_cannot_authenticate_with_a_wrong_password(): void
    {
        User::factory()->create([
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ]);

        $this->post('/login', [
            'username' => 'jdoe',
            'password' => 'wrong-pass',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_disabled_users_cannot_authenticate(): void
    {
        User::factory()->inactive()->create([
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ]);

        $this->post('/login', [
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_remember_me_sets_the_recaller_cookie(): void
    {
        $user = User::factory()->create([
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ]);

        $response = $this->post('/login', [
            'username' => 'jdoe',
            'password' => 'secret-pass',
            'remember' => true,
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertCookie(Auth::guard('web')->getRecallerName());
    }

    public function test_login_without_remember_me_sets_no_recaller_cookie(): void
    {
        User::factory()->create([
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ]);

        $this->post('/login', [
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ])->assertCookieMissing(Auth::guard('web')->getRecallerName());
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', [
                'username' => 'jdoe',
                'password' => 'wrong-pass',
            ]);
        }

        $response = $this->post('/login', [
            'username' => 'jdoe',
            'password' => 'secret-pass',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('username')
        );
        $this->assertGuest();
    }

    public function test_users_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_logged_in_users_are_sent_away_from_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect('/dashboard');
    }
}
