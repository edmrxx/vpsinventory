<?php

namespace Tests\Feature\Settings;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use App\Support\SettingsSchema;
use App\Support\ThemeStyles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->settings()->flush();
    }

    protected function settings(): Settings
    {
        return app(Settings::class);
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /* ------------------------------------------------------- The key store */

    public function test_an_unsaved_setting_falls_back_to_its_default(): void
    {
        $this->assertSame(0, Setting::count());

        $this->assertSame(
            SettingsSchema::default('company.name'),
            $this->settings()->get('company.name')
        );
    }

    public function test_a_saved_setting_wins_over_the_default(): void
    {
        $this->settings()->set('company.name', 'MAF Lending Corp.');

        $this->assertSame('MAF Lending Corp.', $this->settings()->get('company.name'));
        $this->assertDatabaseHas('settings', [
            'key' => 'company.name',
            'value' => 'MAF Lending Corp.',
        ]);
    }

    public function test_saving_twice_updates_one_row(): void
    {
        $this->settings()->set('company.name', 'First');
        $this->settings()->set('company.name', 'Second');

        $this->assertSame(1, Setting::where('key', 'company.name')->count());
        $this->assertSame('Second', $this->settings()->get('company.name'));
    }

    public function test_values_come_back_in_the_type_the_schema_declares(): void
    {
        $this->settings()->set('theme.sidebar_collapsed', true);

        $this->assertTrue($this->settings()->get('theme.sidebar_collapsed'));
        $this->assertIsBool($this->settings()->get('theme.sidebar_collapsed'));

        $this->settings()->set('theme.sidebar_collapsed', false);

        $this->assertFalse($this->settings()->get('theme.sidebar_collapsed'));
        $this->assertIsBool($this->settings()->get('theme.sidebar_collapsed'));
    }

    public function test_reading_many_settings_costs_one_query(): void
    {
        $this->settings()->set('company.name', 'Cached Co.');
        $this->settings()->flush();

        DB::enableQueryLog();

        $this->settings()->get('company.name');
        $this->settings()->get('company.email');
        $this->settings()->get('theme.brand_color');
        $this->settings()->get('theme.radius');
        $this->settings()->all();

        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $q) => str_contains($q['query'], 'settings'))
            ->count();

        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $queries, 'The settings table should be read once.');
    }

    public function test_saving_clears_the_cache(): void
    {
        $this->assertSame('MAF Inventory', $this->settings()->get('company.name'));

        $this->settings()->set('company.name', 'Changed');

        // A brand new manager, as a later request would have.
        app()->forgetInstance(Settings::class);

        $this->assertSame('Changed', app(Settings::class)->get('company.name'));
    }

    public function test_a_group_is_returned_by_short_name(): void
    {
        $this->settings()->set('company.name', 'Group Co.');

        $group = $this->settings()->group('company');

        $this->assertSame('Group Co.', $group['name']);
        $this->assertArrayHasKey('email', $group);
        $this->assertArrayNotHasKey('company.name', $group);
    }

    public function test_resetting_a_group_removes_the_rows(): void
    {
        $this->settings()->set('company.name', 'Temporary');
        $this->settings()->set('company.tagline', 'Temporary');

        $this->settings()->resetGroup('company');

        $this->assertSame(0, Setting::where('key', 'like', 'company.%')->count());
        $this->assertSame('MAF Inventory', $this->settings()->get('company.name'));
    }

    /* ------------------------------------------------------------ The pages */

    public function test_the_page_needs_the_view_permission(): void
    {
        $this->actingAs($this->unaudited(fn () => User::factory()->create()))
            ->get('/settings')
            ->assertForbidden();
    }

    public function test_settings_lands_on_the_first_group(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings')
            ->assertRedirect('/settings/company');
    }

    public function test_the_company_group_shows_its_fields_and_values(): void
    {
        $this->settings()->set('company.name', 'Shown Co.');

        $this->actingAs($this->admin())
            ->get('/settings/company')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Show')
                ->where('group.key', 'company')
                ->where('group.label', 'Company Information')
                ->has('fields', count(SettingsSchema::fieldsOf('company')))
                ->where('values.name', 'Shown Co.')
                ->where('can.update', true)
            );
    }

    public function test_every_declared_group_is_listed(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings/company')
            ->assertInertia(fn ($page) => $page->has('groups', count(SettingsSchema::groups())));
    }

    public function test_the_theme_group_shows_its_fields(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings/theme')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('group.key', 'theme')
                ->where('values.brand_color', '#5b3df5')
            );
    }

    public function test_the_system_group_carries_the_currency(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings/system')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('group.key', 'system')
                ->where('values.currency_symbol', '₱')
                ->where('values.currency_code', 'PHP')
            );
    }

    /**
     * The API group holds the token lifetime, added in phase 13.
     *
     * It used to be an empty group, which is why it was listed in the test
     * below rather than here.
     */
    public function test_the_api_group_carries_the_token_lifetime(): void
    {
        $this->actingAs($this->admin())
            ->get('/settings/api')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('group.key', 'api')
                ->where('values.token_lifetime_days', 30)
            );
    }

    public function test_a_group_with_no_fields_yet_still_opens(): void
    {
        foreach (['notifications'] as $group) {
            $this->actingAs($this->admin())
                ->get("/settings/{$group}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('group.key', $group)
                    ->has('fields', 0)
                );
        }
    }

    public function test_an_unknown_group_is_not_found(): void
    {
        $this->actingAs($this->admin())->get('/settings/nonsense')->assertNotFound();
    }

    /* ----------------------------------------------------------- The saving */

    public function test_an_admin_can_save_the_company_information(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/company', [
                'values' => [
                    'name' => 'MAF Lending Corp.',
                    'tagline' => 'Serving since 1998',
                    'email' => 'hello@maf.test',
                    'phone' => '+63 900 000 0000',
                    'address' => 'Ormoc City, Leyte',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        app()->forgetInstance(Settings::class);

        $this->assertSame('MAF Lending Corp.', app(Settings::class)->get('company.name'));
        $this->assertSame('hello@maf.test', app(Settings::class)->get('company.email'));
    }

    public function test_saving_needs_the_update_permission(): void
    {
        $actor = $this->unaudited(
            fn () => User::factory()->withPermissions('settings.view')->create()
        );

        $this->actingAs($actor)
            ->put('/settings/company', ['values' => ['name' => 'Sneaky']])
            ->assertForbidden();

        $this->assertSame('MAF Inventory', $this->settings()->get('company.name'));
    }

    public function test_the_rules_in_the_schema_are_applied(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put('/settings/company', ['values' => ['name' => '']])
            ->assertSessionHasErrors('values.name');

        $this->actingAs($admin)
            ->put('/settings/company', [
                'values' => ['name' => 'Fine', 'email' => 'not-an-email'],
            ])
            ->assertSessionHasErrors('values.email');

        $this->actingAs($admin)
            ->put('/settings/theme', ['values' => ['brand_color' => 'blue']])
            ->assertSessionHasErrors('values.brand_color');

        $this->actingAs($admin)
            ->put('/settings/theme', [
                'values' => ['brand_color' => '#112233', 'radius' => 'wobbly'],
            ])
            ->assertSessionHasErrors('values.radius');
    }

    public function test_a_key_outside_the_group_is_ignored(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/company', [
                'values' => [
                    'name' => 'Allowed',
                    'theme.brand_color' => '#ff0000',
                    'made.up.key' => 'nope',
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('settings', ['key' => 'made.up.key']);
        $this->assertDatabaseMissing('settings', ['key' => 'theme.brand_color']);
        $this->assertDatabaseHas('settings', ['key' => 'company.name', 'value' => 'Allowed']);
    }

    public function test_an_admin_can_put_a_group_back_to_the_defaults(): void
    {
        $this->settings()->set('company.name', 'Temporary');

        $this->actingAs($this->admin())
            ->delete('/settings/company')
            ->assertRedirect()
            ->assertSessionHas('success');

        app()->forgetInstance(Settings::class);

        $this->assertSame('MAF Inventory', app(Settings::class)->get('company.name'));
    }

    /* ------------------------------------------------------------- Effects */

    public function test_the_company_name_reaches_every_page(): void
    {
        $this->settings()->set('company.name', 'Shared Co.');
        $this->settings()->set('company.tagline', 'A tagline');

        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('appName', 'Shared Co.')
                ->where('company.name', 'Shared Co.')
                ->where('company.tagline', 'A tagline')
            );
    }

    public function test_the_company_name_reaches_the_login_page_too(): void
    {
        $this->settings()->set('company.name', 'Guest Co.');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Guest Co.', false);
    }

    public function test_the_theme_writes_css_variables_into_the_page(): void
    {
        $this->settings()->set('theme.brand_color', '#0f766e');
        $this->settings()->set('theme.radius', 'sharp');

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertOk()
            ->assertSee('id="maf-theme"', false)
            ->assertSee('--color-brand-600:#0f766e', false)
            ->assertSee('--radius-field:0.25rem', false);
    }

    public function test_the_default_theme_writes_no_css_at_all(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('id="maf-theme"', false);
    }

    public function test_the_density_setting_reaches_the_html_tag(): void
    {
        $this->settings()->set('theme.density', 'compact');

        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertSee('data-density="compact"', false);
    }

    public function test_the_brand_ramp_is_mixed_from_the_one_colour(): void
    {
        $this->settings()->set('theme.brand_color', '#0f766e');

        app()->forgetInstance(ThemeStyles::class);

        $css = app(ThemeStyles::class)->css();

        // Ten steps plus the tinted shadow.
        foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as $step) {
            $this->assertStringContainsString("--color-brand-{$step}:", $css);
        }

        $this->assertStringContainsString('--shadow-brand:', $css);
        $this->assertStringContainsString('#0f766e', $css);
    }

    public function test_the_sidebar_default_follows_the_theme_until_the_cookie_is_set(): void
    {
        $this->settings()->set('theme.sidebar_collapsed', true);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('sidebarCollapsed', true));

        // Once the person folds or unfolds it themselves, the cookie wins.
        $this->actingAs($admin)
            ->withCookie('sidebar_collapsed', '0')
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('sidebarCollapsed', false));
    }

    /* --------------------------------------------------------------- Audit */

    public function test_a_change_is_written_to_the_audit_trail(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/company', [
                'values' => ['name' => 'Audited Co.', 'tagline' => 'Operations'],
            ]);

        $trail = AuditTrail::latest('id')->first();

        $this->assertNotNull($trail);
        $this->assertSame(AuditAction::Update, $trail->action);
        $this->assertSame('Settings', $trail->module);
        $this->assertStringContainsString('company.name', $trail->description);
        $this->assertSame('MAF Inventory', $trail->properties['changed']['company.name']['from']);
        $this->assertSame('Audited Co.', $trail->properties['changed']['company.name']['to']);

        // The tagline was posted with the value it already had, so it is not
        // listed as a change.
        $this->assertArrayNotHasKey('company.tagline', $trail->properties['changed']);
    }

    public function test_saving_the_same_values_writes_no_audit_row(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings/company', ['values' => ['name' => 'MAF Inventory']]);

        $this->assertSame(0, AuditTrail::where('module', 'Settings')->count());
    }
}
