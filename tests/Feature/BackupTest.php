<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\User;
use App\Services\BackupMaker;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 35: backups, and the settings that govern them.
 *
 * Every test here writes to a FAKE private disk. A test that pruned the real
 * storage/app/private/backups would delete real backups on this machine —
 * the accident TestCase already guards the public disk against.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function staff(): User
    {
        return $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
    }

    /** @return array<string, string> Every entry of the newest backup. */
    protected function newestBackup(): array
    {
        $name = app(BackupMaker::class)->all()[0]['name'];
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(BackupMaker::disk()->path('backups/'.$name)) === true);

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $entries;
    }

    /* ------------------------------------------------------------- Making */

    public function test_an_admin_makes_a_backup_with_the_database_photos_and_restore_note(): void
    {
        $admin = $this->admin();
        $this->unaudited(fn () => Customer::factory()->create(['name' => "O'Brien Hardware"]));
        Storage::disk('public')->put('products/cement.jpg', 'photo-bytes');

        $this->actingAs($admin)->post('/backups')
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Backup made: backup-'));

        $entries = $this->newestBackup();

        $this->assertArrayHasKey('database.sql', $entries);
        $this->assertArrayHasKey('RESTORE.txt', $entries);
        $this->assertArrayHasKey('manifest.json', $entries);
        $this->assertSame('photo-bytes', $entries['files/products/cement.jpg']);

        $sql = $entries['database.sql'];
        $this->assertStringContainsString('CREATE TABLE "users"', $sql);
        $this->assertStringContainsString('INSERT INTO "customers"', $sql);
        // A quote in a name is escaped, not left to break the statement.
        $this->assertStringContainsString("'O''Brien Hardware'", $sql);

        $manifest = json_decode($entries['manifest.json'], true);
        $this->assertSame($admin->name.' (@'.$admin->username.')', $manifest['made_by']);
        $this->assertSame(1, $manifest['files']);

        $this->assertTrue(AuditTrail::query()
            ->where('module', 'Backups')->where('action', 'create')
            ->where('description', 'like', 'Made backup [backup-%')->exists());
    }

    public function test_a_backup_never_carries_the_env_file_or_the_app_key(): void
    {
        $this->actingAs($this->admin())->post('/backups');
        $entries = $this->newestBackup();

        foreach (array_keys($entries) as $name) {
            $this->assertStringNotContainsString('.env', $name);
        }

        $key = (string) config('app.key');
        foreach ($entries as $content) {
            $this->assertStringNotContainsString($key, $content);
        }
    }

    public function test_the_session_and_cache_rows_are_left_out_but_their_tables_kept(): void
    {
        \DB::table('cache')->insert(['key' => 'secret-cache-row', 'value' => 'x', 'expiration' => time() + 60]);

        $this->actingAs($this->admin())->post('/backups');
        $sql = $this->newestBackup()['database.sql'];

        $this->assertStringContainsString('CREATE TABLE "cache"', $sql);
        $this->assertStringNotContainsString('secret-cache-row', $sql);
    }

    public function test_the_photos_stay_out_when_the_setting_says_so(): void
    {
        app(Settings::class)->set('backup.include_files', false);
        Storage::disk('public')->put('products/cement.jpg', 'photo-bytes');

        $this->actingAs($this->admin())->post('/backups');

        $this->assertArrayNotHasKey('files/products/cement.jpg', $this->newestBackup());
    }

    /**
     * The proof that matters: the SQL puts the books back. Imported into a
     * fresh database, every table holds the rows it held.
     */
    public function test_the_database_file_restores_every_row(): void
    {
        $this->unaudited(fn () => Customer::factory()->count(3)->create());
        $this->actingAs($this->admin())->post('/backups');

        $restored = new PDO('sqlite::memory:');
        $restored->exec($this->newestBackup()['database.sql']);

        foreach (['users', 'customers', 'roles', 'permissions', 'settings', 'payment_methods'] as $table) {
            $this->assertSame(
                \DB::table($table)->count(),
                (int) $restored->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn(),
                "The restored {$table} table lost rows.",
            );
        }
    }

    public function test_only_the_newest_copies_are_kept(): void
    {
        app(Settings::class)->set('backup.keep_copies', 2);
        $admin = $this->admin();

        foreach (['2026-11-10 08:00:00', '2026-11-11 08:00:00', '2026-11-12 08:00:00'] as $moment) {
            Carbon::setTestNow($moment);
            $this->actingAs($admin)->post('/backups');
        }
        Carbon::setTestNow();

        $this->assertSame(
            ['backup-2026-11-12-080000.zip', 'backup-2026-11-11-080000.zip'],
            array_column(app(BackupMaker::class)->all(), 'name'),
        );
    }

    public function test_two_backups_in_one_second_both_survive_newest_first(): void
    {
        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->actingAs($admin = $this->admin())->post('/backups');
        $this->actingAs($admin)->post('/backups');
        Carbon::setTestNow();

        $this->assertSame(
            ['backup-2026-11-13-090000-2.zip', 'backup-2026-11-13-090000.zip'],
            array_column(app(BackupMaker::class)->all(), 'name'),
        );
    }

    public function test_the_command_makes_one_for_the_scheduler(): void
    {
        $this->assertSame(0, Artisan::call('backup:run'));
        $this->assertStringContainsString('Made backup-', Artisan::output());
        $this->assertCount(1, app(BackupMaker::class)->all());

        $this->assertTrue(AuditTrail::query()->where('description', 'like', '%on schedule.')->exists());
    }

    public function test_the_schedule_follows_the_setting(): void
    {
        $events = fn () => collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'backup:run'))
            ->filter(fn ($event) => $event->filtersPass($this->app));

        $this->assertCount(0, $events(), 'Off by default.');

        app(Settings::class)->set('backup.frequency', 'daily');
        $this->assertSame(['0 2 * * *'], $events()->pluck('expression')->values()->all());
    }

    /* ------------------------------------------------------ Downloading */

    public function test_an_admin_downloads_a_backup_and_it_is_audited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/backups');
        $name = app(BackupMaker::class)->all()[0]['name'];

        $this->actingAs($admin)->get("/backups/{$name}/download")
            ->assertOk()
            ->assertDownload($name);

        $this->assertTrue(AuditTrail::query()->where('description', "Downloaded backup [{$name}].")->exists());
    }

    public function test_nothing_but_a_backup_name_is_ever_served(): void
    {
        $admin = $this->admin();
        BackupMaker::disk()->put('.env', 'APP_KEY=secret');

        foreach ([
            '/backups/..%2F.env/download',
            '/backups/.env/download',
            '/backups/backup-..%2F..%2F.env/download',
            '/backups/backup-2026-01-01-000000.zip/download', // well-formed, but not there
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertNotFound();
        }

        $this->assertNull(app(BackupMaker::class)->path('../.env'));
    }

    /* ---------------------------------------------------------- Deleting */

    public function test_an_admin_deletes_a_backup(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/backups');
        $name = app(BackupMaker::class)->all()[0]['name'];

        $this->actingAs($admin)->delete("/backups/{$name}")->assertRedirect();

        $this->assertSame([], app(BackupMaker::class)->all());
        $this->assertTrue(AuditTrail::query()->where('description', "Deleted backup [{$name}].")->exists());
    }

    /* ------------------------------------------------------------ Access */

    public function test_staff_can_neither_see_make_download_nor_delete_one(): void
    {
        $this->actingAs($this->admin())->post('/backups');
        $name = app(BackupMaker::class)->all()[0]['name'];
        $staff = $this->staff();

        $this->actingAs($staff)->get('/backups')->assertForbidden();
        $this->actingAs($staff)->post('/backups')->assertForbidden();
        $this->actingAs($staff)->get("/backups/{$name}/download")->assertForbidden();
        $this->actingAs($staff)->delete("/backups/{$name}")->assertForbidden();

        $this->assertCount(1, app(BackupMaker::class)->all());
    }

    public function test_the_screen_lists_the_backups_and_the_settings(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/backups');

        $this->actingAs($admin)->get('/backups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Backups/Index')
                ->has('backups', 1)
                ->where('settings.frequency', 'off')
                ->where('settings.keep_copies', 7)
                ->where('settings.include_files', true)
                ->where('can.create', true)
                ->where('can.download', true));
    }

    /* ----------------------------------------------------- Print layouts */

    public function test_every_page_receives_the_print_layout_with_defaults_that_change_nothing(): void
    {
        $this->actingAs($this->admin())->get('/backups')
            ->assertInertia(fn ($page) => $page
                ->where('printing.paper_size', 'auto')
                ->where('printing.margins', 'auto')
                ->where('printing.show_logo', true)
                ->where('printing.show_company_details', true)
                ->where('printing.quotation_terms', '')
                ->where('printing.footer_note', ''));
    }

    public function test_the_print_settings_save_a_known_paper_and_refuse_an_unknown_one(): void
    {
        $admin = $this->admin();
        $values = [
            'paper_size' => 'long',
            'margins' => 'normal',
            'show_logo' => true,
            'show_company_details' => false,
            'quotation_terms' => 'Prices hold for 15 days.',
            'purchase_order_terms' => '',
            'footer_note' => 'www.goenterprises.ph',
        ];

        $this->actingAs($admin)
            ->put('/settings/printing', ['values' => $values])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        app()->forgetInstance(Settings::class);
        $this->assertSame('long', app(Settings::class)->get('printing.paper_size'));
        $this->assertFalse((bool) app(Settings::class)->get('printing.show_company_details'));

        $this->actingAs($admin)
            ->put('/settings/printing', ['values' => ['paper_size' => 'A0'] + $values])
            ->assertSessionHasErrors('values.paper_size');

        app()->forgetInstance(Settings::class);
        $this->assertSame('long', app(Settings::class)->get('printing.paper_size'));
    }
}
