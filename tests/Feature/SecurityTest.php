<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The security review, written down so it keeps being true.
 *
 * Each test states one rule the app must follow. If somebody adds a route
 * and forgets the gate, the first test here fails and names it.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * A route's middleware with the group names replaced by what they hold.
     *
     * `gatherMiddleware()` leaves "web" as the single word "web", so looking
     * for a class inside it finds nothing.
     *
     * @return list<string>
     */
    protected function expandedMiddleware(\Illuminate\Routing\Route $route): array
    {
        $groups = Route::getMiddlewareGroups();

        return collect($route->gatherMiddleware())
            ->flatMap(fn ($name) => is_string($name) && isset($groups[$name])
                ? $groups[$name]
                : [$name])
            ->map(fn ($name) => is_string($name) ? $name : get_class($name))
            ->values()
            ->all();
    }

    /* ------------------------------------------------- Every route is gated */

    public function test_every_route_is_either_public_on_purpose_or_gated(): void
    {
        // The only routes a stranger may reach. Everything else must sit
        // behind auth, auth:sanctum, or the guest middleware.
        $allowedPublic = [
            'login',          // the login page and its POST
            'up',            // the health check
            'api/v1/login',  // the token endpoint
            'api/{fallbackPlaceholder}',
            '/',             // redirects to the dashboard, which is gated
        ];

        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();

            $isGated = collect($middleware)->contains(
                fn ($name) => is_string($name) && (
                    $name === 'auth'
                    || str_starts_with($name, 'auth:')
                    || $name === 'guest'
                    || str_starts_with($name, 'guest:')
                )
            );

            if (! $isGated && ! in_array($uri, $allowedPublic, true)) {
                $ungated[] = $route->methods()[0].' /'.$uri;
            }
        }

        $this->assertSame(
            [],
            $ungated,
            "These routes have no authentication gate:\n  ".implode("\n  ", $ungated)
        );
    }

    public function test_every_writing_route_in_a_module_needs_a_permission(): void
    {
        // A route that changes data must name a permission, with the two
        // exceptions listed here.
        $exceptions = [
            'logout',               // any signed-in user may leave
            'login',                // the login POST itself
            'api/v1/login',
            'api/v1/logout',
            'api/v1/logout-all',
            'dashboard/refresh',    // only clears a cache, and is throttled
            'appearance',           // a person's own light or dark screen, and is throttled
            '/',                    // a GET-only redirect to the dashboard
        ];

        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $writes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);

            if ($writes === [] || in_array($route->uri(), $exceptions, true)) {
                continue;
            }

            $hasPermission = collect($route->gatherMiddleware())->contains(
                fn ($name) => is_string($name) && (
                    str_starts_with($name, 'permission:')
                    || str_starts_with($name, 'role:')
                    || str_starts_with($name, 'role_or_permission:')
                )
            );

            if (! $hasPermission) {
                $missing[] = $route->methods()[0].' /'.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These routes change data but name no permission:\n  ".implode("\n  ", $missing)
        );
    }

    /* --------------------------------------------------------------- CSRF */

    /**
     * The framework skips the CSRF check whenever the app runs in the
     * testing environment, so this cannot be proved by sending a request.
     * What it can prove is that the check guards every web route that
     * writes, and guards no API route.
     */
    public function test_the_csrf_check_guards_the_web_routes_and_not_the_api(): void
    {
        $csrf = \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class;

        $webWriters = [];
        $apiWithCsrf = [];

        foreach (Route::getRoutes() as $route) {
            $writes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
            $middleware = $this->expandedMiddleware($route);
            $isApi = str_starts_with($route->uri(), 'api/');

            if ($isApi && in_array($csrf, $middleware, true)) {
                $apiWithCsrf[] = $route->uri();

                continue;
            }

            if (! $isApi && $writes !== [] && ! in_array($csrf, $middleware, true)) {
                $webWriters[] = $route->methods()[0].' /'.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $webWriters,
            "These web routes write without the CSRF check:\n  ".implode("\n  ", $webWriters)
        );

        $this->assertSame(
            [],
            $apiWithCsrf,
            'The API is stateless, so it must not carry the CSRF check: '
                .implode(', ', $apiWithCsrf)
        );
    }

    public function test_the_api_does_not_need_a_csrf_token(): void
    {
        $this->unaudited(
            fn () => User::factory()->create(['username' => 'apiuser', 'password' => 'api-pass-123'])
        );

        $this->withMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

        // Stateless by design, so a token login works with no session.
        $this->postJson('/api/v1/login', [
            'username' => 'apiuser',
            'password' => 'api-pass-123',
        ])->assertOk();
    }

    /* ----------------------------------------------------------- Passwords */

    public function test_a_password_is_stored_as_a_bcrypt_hash(): void
    {
        $user = $this->unaudited(
            fn () => User::factory()->create(['password' => 'plain-text-pass'])
        );

        $this->assertNotSame('plain-text-pass', $user->password);
        $this->assertStringStartsWith('$2y$', $user->password);
        $this->assertTrue(Hash::check('plain-text-pass', $user->password));
    }

    public function test_a_password_is_never_sent_to_the_browser(): void
    {
        $user = $this->unaudited(fn () => User::factory()->admin()->create());

        $responses = [
            $this->actingAs($user)->get('/users'),
            $this->actingAs($user)->get("/users/{$user->id}"),
            $this->actingAs($user)->get('/dashboard'),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            $response->assertDontSee('$2y$', false);
            $response->assertDontSee('remember_token', false);
        }
    }

    public function test_the_api_never_sends_a_password(): void
    {
        $user = $this->unaudited(fn () => User::factory()->admin()->create());
        $token = $user->createToken('test')->plainTextToken;

        foreach (['/api/v1/me', '/api/v1/users', "/api/v1/users/{$user->id}"] as $endpoint) {
            $response = $this->withToken($token)->getJson($endpoint)->assertOk();

            $body = $response->getContent();

            $this->assertStringNotContainsString('$2y$', $body);
            $this->assertStringNotContainsString('password', $body);
            $this->assertStringNotContainsString('remember_token', $body);
        }
    }

    /* ------------------------------------------------------- SQL injection */

    public function test_a_crafted_sort_column_cannot_reach_the_query(): void
    {
        $admin = $this->unaudited(fn () => User::factory()->admin()->create());

        // Only whitelisted columns are allowed through, so this is ignored
        // rather than run.
        $this->actingAs($admin)
            ->get('/users?sort='.urlencode('name; DROP TABLE users'))
            ->assertOk();

        $this->assertDatabaseCount('users', 1);

        $this->actingAs($admin)
            ->get('/users?sort='.urlencode('(SELECT password FROM users)'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.sort', 'name'));
    }

    public function test_a_crafted_search_is_treated_as_text(): void
    {
        $admin = $this->unaudited(fn () => User::factory()->admin()->create());

        $this->actingAs($admin)
            ->get('/users?search='.urlencode("' OR 1=1 --"))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('users.data', 0));

        $this->assertDatabaseCount('users', 1);
    }

    /* ------------------------------------------------------------- Session */

    public function test_the_session_id_changes_on_login(): void
    {
        $this->unaudited(
            fn () => User::factory()->create(['username' => 'jdoe', 'password' => 'secret-pass'])
        );

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['username' => 'jdoe', 'password' => 'secret-pass']);

        $this->assertNotSame(
            $before,
            session()->getId(),
            'The session id must change on login, to stop session fixation.'
        );
    }

    public function test_logging_out_empties_the_session(): void
    {
        $user = $this->unaudited(fn () => User::factory()->create());

        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $this->assertNull(session('_token_was_kept', null));
    }

    public function test_the_session_cookie_is_http_only_and_same_site(): void
    {
        $this->assertTrue(config('session.http_only'), 'The session cookie must be http only.');
        $this->assertSame('lax', config('session.same_site'));
        $this->assertTrue(config('session.encrypt'), 'The session payload should be encrypted.');
    }

    /* ------------------------------------------------------------- Headers */

    public function test_the_safety_headers_are_sent(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_a_signed_in_page_is_not_cached_by_shared_caches(): void
    {
        $user = $this->unaudited(fn () => User::factory()->admin()->create());

        $response = $this->actingAs($user)->get('/dashboard');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /* ----------------------------------------------------------- Throttling */

    public function test_the_web_login_is_throttled(): void
    {
        $this->unaudited(fn () => User::factory()->create(['username' => 'jdoe']));

        foreach (range(1, 6) as $attempt) {
            $response = $this->post('/login', [
                'username' => 'jdoe',
                'password' => 'wrong-pass',
            ]);
        }

        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('username')
        );
    }

    public function test_the_api_login_is_throttled(): void
    {
        $this->unaudited(fn () => User::factory()->create(['username' => 'jdoe']));

        $lastStatus = null;

        foreach (range(1, 7) as $attempt) {
            $lastStatus = $this->postJson('/api/v1/login', [
                'username' => 'jdoe',
                'password' => 'wrong-pass',
            ])->getStatusCode();
        }

        $this->assertSame(422, $lastStatus);

        $response = $this->postJson('/api/v1/login', [
            'username' => 'jdoe',
            'password' => 'wrong-pass',
        ]);

        $this->assertStringContainsString(
            'Too many login attempts',
            $response->json('errors.username.0') ?? ''
        );
    }

    /* ---------------------------------------------------- Escaping / tokens */

    public function test_a_stored_script_tag_is_escaped_in_the_page(): void
    {
        $admin = $this->unaudited(fn () => User::factory()->admin()->create());

        $this->unaudited(
            fn () => User::factory()->create(['name' => '<script>alert(1)</script>'])
        );

        $response = $this->actingAs($admin)->get('/users')->assertOk();

        // The name travels inside the data-page attribute, HTML escaped.
        $response->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_a_token_only_works_for_its_own_user(): void
    {
        $owner = $this->unaudited(fn () => User::factory()->admin()->create());
        $other = $this->unaudited(fn () => User::factory()->create());

        $token = $owner->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.username', $owner->username);

        $this->assertNotSame($owner->id, $other->id);
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $user = $this->unaudited(fn () => User::factory()->admin()->create());
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count(), 'The token row must be gone.');

        $this->forgetSignedInUser();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    /**
     * A token stops working at the end of its life.
     *
     * Sanctum's own `expiration` is left null in config, so the lifetime is
     * put on each token as it is made, from the settings page. Without this
     * a token is a password that can never be changed: it keeps working for
     * as long as nobody thinks to revoke it.
     */
    public function test_a_token_expires_on_its_own(): void
    {
        $this->unaudited(fn () => User::factory()->admin()->create([
            'username' => 'phone_user',
            'password' => 'phone-pass-123',
        ]));

        $token = $this->postJson('/api/v1/login', [
            'username' => 'phone_user',
            'password' => 'phone-pass-123',
            'device_name' => 'Test phone',
        ])->assertOk()->json('data.token');

        $this->forgetSignedInUser();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $days = (int) settings('api.token_lifetime_days');
        $this->assertGreaterThan(0, $days, 'The lifetime setting must be a real number of days.');

        // One day inside the window it still works.
        $this->travel($days - 1)->days();
        $this->forgetSignedInUser();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        // One day past it, it does not.
        $this->travel(2)->days();
        $this->forgetSignedInUser();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

        $this->travelBack();
    }

    /**
     * Re-seeding must not put the default password back.
     *
     * `db:seed` runs again on most deploys. An `updateOrCreate` in the
     * admin seeder would quietly reset the password to the one printed in
     * the README, and switch a disabled account back on with it.
     */
    public function test_re_seeding_does_not_reset_the_administrator_password(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('username', 'admin')->firstOrFail();

        $this->unaudited(fn () => $admin->update([
            'password' => 'a-long-chosen-password',
            'is_active' => false,
        ]));

        $this->seed(AdminUserSeeder::class);

        $admin->refresh();

        $this->assertTrue(
            Hash::check('a-long-chosen-password', $admin->password),
            'The seeder overwrote a password its owner had changed.'
        );
        $this->assertFalse(
            Hash::check('admin123', $admin->password),
            'The default password came back.'
        );
        $this->assertFalse($admin->is_active, 'The seeder switched a disabled account on.');
    }

    /**
     * Re-seeding must not hand the Admin role back either.
     *
     * The same deploy problem as the password. An account deliberately
     * demoted to Staff would be an administrator again after the next
     * `db:seed`, and whatever role it had been given instead would be gone.
     */
    public function test_re_seeding_does_not_give_the_administrator_role_back(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('username', 'admin')->firstOrFail();
        $this->assertTrue($admin->hasRole('Admin'), 'The first run should grant Admin.');

        $this->unaudited(fn () => $admin->syncRoles(['Staff']));

        $this->seed(AdminUserSeeder::class);

        $admin->refresh();

        $this->assertFalse(
            $admin->hasRole('Admin'),
            'The seeder made a demoted account an administrator again.'
        );
        $this->assertTrue($admin->hasRole('Staff'), 'The seeder stripped the role it had been given.');
    }

    /**
     * The audit trail must not carry what a sale cost.
     *
     * Cost, and so the margin, is held back behind `sales.view_cost`. The
     * trail is readable by anyone with `audit.view`, which is a different
     * permission, so a cost written into a trail description or its
     * properties walks straight around the wall.
     */
    public function test_the_audit_trail_does_not_carry_the_cost_of_a_sale(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $product = $this->unaudited(fn () => Product::factory()->create());
        $customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100],
        ]);

        $this->actingAs($this->unaudited(fn () => User::factory()->admin()->create()))
            ->post('/sales', [
                'customer_id' => $customer->id,
                'sale_date' => now()->toDateString(),
                'discount' => 0,
                'lines' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => '500.00']],
            ]);

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertNotSame('0.00', (string) $sale->total_cost, 'The sale should have a cost to leak.');

        $rows = AuditTrail::where('module', 'Sales')->get();

        $this->assertTrue($rows->isNotEmpty(), 'The sale should have written a trail row.');

        foreach ($rows as $row) {
            $text = $row->description.' '.json_encode($row->properties);

            foreach (['total_cost', 'unit_cost', 'gross_profit', 'margin'] as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $text,
                    "The Sales trail row [{$row->id}] names [{$word}], which is cost data."
                );
            }
        }
    }

    public function test_an_inactive_account_cannot_get_a_token(): void
    {
        $this->unaudited(fn () => User::factory()->inactive()->create([
            'username' => 'switched_off',
            'password' => 'still-right-pass',
        ]));

        $this->postJson('/api/v1/login', [
            'username' => 'switched_off',
            'password' => 'still-right-pass',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'This account is not active.');
    }

    public function test_a_wrong_username_and_a_wrong_password_read_the_same(): void
    {
        $this->unaudited(
            fn () => User::factory()->create(['username' => 'realuser', 'password' => 'real-pass-1'])
        );

        $wrongUser = $this->postJson('/api/v1/login', [
            'username' => 'nosuchuser',
            'password' => 'real-pass-1',
        ]);

        $wrongPass = $this->postJson('/api/v1/login', [
            'username' => 'realuser',
            'password' => 'wrong-pass-1',
        ]);

        // Neither reply says whether the username exists.
        $this->assertSame(
            $wrongUser->json('errors.username.0'),
            $wrongPass->json('errors.username.0')
        );
    }
}
