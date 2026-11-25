<?php

namespace Tests\Feature\Settings;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Models\User;
use App\Support\SettingImage;
use App\Support\Settings;
use App\Support\SettingsSchema;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The company logo, uploaded from the Settings page.
 *
 * What these tests guard is mostly what nobody would see: an old logo whose
 * file is never removed, the Save button quietly putting the old logo back
 * over a fresh upload, an SVG getting in, and a value that is not one of
 * ours being trusted as a path to delete.
 */
class SettingImageTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = '/settings/company/images/logo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake('public');

        app(Settings::class)->flush();
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function logo(): mixed
    {
        app()->forgetInstance(Settings::class);

        return app(Settings::class)->get('company.logo');
    }

    /** The disk path behind a stored value. */
    protected function diskPath(string $value): string
    {
        return substr($value, strlen('/storage/'));
    }

    protected function upload(?User $user = null, ?UploadedFile $file = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user ?? $this->admin())
            ->post(static::URL, [
                'image' => $file ?? UploadedFile::fake()->image('logo.png', 800, 400),
            ]);
    }

    /* ------------------------------------------------------------ Uploading */

    public function test_an_admin_can_upload_a_logo(): void
    {
        $this->upload()->assertRedirect()->assertSessionHas('success', 'Logo updated.');

        $logo = $this->logo();

        $this->assertStringStartsWith('/storage/settings/', $logo);
        Storage::disk('public')->assertExists($this->diskPath($logo));
        $this->assertTrue(SettingImage::isUploaded($logo));
    }

    public function test_a_large_picture_is_shrunk(): void
    {
        $this->upload(file: UploadedFile::fake()->image('logo.png', 2000, 1000));

        [$width, $height] = getimagesize(Storage::disk('public')->path($this->diskPath($this->logo())));

        $this->assertSame(SettingImage::MAX_EDGE, $width);
        $this->assertSame(SettingImage::MAX_EDGE / 2, $height);
    }

    public function test_the_tab_icons_are_cut_from_the_logo(): void
    {
        $this->upload();

        $disk = Storage::disk('public');

        $disk->assertExists('settings/icons/favicon.png');
        $disk->assertExists('settings/icons/apple-touch-icon.png');

        $this->assertSame([64, 64], array_slice(getimagesize($disk->path('settings/icons/favicon.png')), 0, 2));
        $this->assertSame([180, 180], array_slice(getimagesize($disk->path('settings/icons/apple-touch-icon.png')), 0, 2));

        // And the page points the browser at them. Still signed in from the
        // upload, so the dashboard rather than the login page.
        $this->get('/dashboard')
            ->assertSee('/storage/settings/icons/favicon.png?v=', false)
            ->assertSee('/storage/settings/icons/apple-touch-icon.png?v=', false);
    }

    public function test_without_an_upload_the_shipped_icons_are_used(): void
    {
        $this->get('/login')
            ->assertSee('images/favicon.png', false)
            ->assertDontSee('settings/icons/favicon.png', false);
    }

    public function test_a_new_upload_removes_the_old_file(): void
    {
        $admin = $this->admin();

        $this->upload($admin);
        $first = $this->logo();

        $this->upload($admin, UploadedFile::fake()->image('second.png', 300, 300));
        $second = $this->logo();

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($this->diskPath($first));
        Storage::disk('public')->assertExists($this->diskPath($second));
    }

    public function test_the_upload_is_written_to_the_audit_trail(): void
    {
        $this->upload();

        $trail = AuditTrail::latest('id')->first();

        $this->assertSame(AuditAction::Update, $trail->action);
        $this->assertSame('Settings', $trail->module);
        $this->assertStringContainsString('company.logo', $trail->description);
        $this->assertSame('/images/brand-mark.png', $trail->properties['changed']['company.logo']['from']);
        $this->assertSame($this->logo(), $trail->properties['changed']['company.logo']['to']);
    }

    /* ------------------------------------------------------------- Refusing */

    public function test_an_svg_is_refused(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->upload(file: $svg)->assertSessionHasErrors('image');

        $this->assertSame('/images/brand-mark.png', $this->logo());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_picture_over_two_megabytes_is_refused(): void
    {
        $this->upload(file: UploadedFile::fake()->image('big.png')->size(3000))
            ->assertSessionHasErrors('image');

        $this->assertSame('/images/brand-mark.png', $this->logo());
    }

    public function test_uploading_needs_the_update_permission(): void
    {
        $viewer = $this->unaudited(
            fn () => User::factory()->withPermissions('settings.view')->create()
        );

        $this->upload($viewer)->assertForbidden();

        $this->actingAs($viewer)->delete(static::URL)->assertForbidden();

        $this->assertSame('/images/brand-mark.png', $this->logo());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_only_a_picture_field_takes_an_upload(): void
    {
        $this->actingAs($this->admin())
            ->post('/settings/company/images/name', [
                'image' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertNotFound();

        $this->actingAs($this->admin())
            ->post('/settings/nowhere/images/logo', [
                'image' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertNotFound();
    }

    /* ----------------------------------------------------------- The form */

    public function test_the_save_button_never_puts_the_old_logo_back(): void
    {
        $admin = $this->admin();

        $this->upload($admin);
        $uploaded = $this->logo();

        // The form still holds the value from when the page opened.
        $this->actingAs($admin)
            ->put('/settings/company', [
                'values' => ['name' => 'GO Enterprises', 'logo' => '/images/brand-mark.png'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($uploaded, $this->logo());
        $this->assertSame('GO Enterprises', app(Settings::class)->get('company.name'));
    }

    public function test_the_form_gets_the_upload_address(): void
    {
        $logo = collect(SettingsSchema::forForm('company'))->firstWhere('name', 'logo');

        $this->assertSame('image', $logo['type']);
        $this->assertSame(static::URL, $logo['upload_url']);
    }

    /* ------------------------------------------------------------- Removing */

    public function test_removing_shows_the_initials_and_deletes_the_files(): void
    {
        $admin = $this->admin();

        $this->upload($admin);
        $uploaded = $this->logo();

        $this->actingAs($admin)
            ->delete(static::URL)
            ->assertRedirect()
            ->assertSessionHas('success', 'Logo removed.');

        $this->assertSame('', $this->logo());
        Storage::disk('public')->assertMissing($this->diskPath($uploaded));
        Storage::disk('public')->assertMissing('settings/icons/favicon.png');
        Storage::disk('public')->assertMissing('settings/icons/apple-touch-icon.png');
    }

    public function test_putting_the_group_back_to_the_defaults_deletes_the_upload(): void
    {
        $admin = $this->admin();

        $this->upload($admin);
        $uploaded = $this->logo();

        $this->actingAs($admin)->delete('/settings/company')->assertRedirect();

        $this->assertSame('/images/brand-mark.png', $this->logo());
        Storage::disk('public')->assertMissing($this->diskPath($uploaded));
        Storage::disk('public')->assertMissing('settings/icons/favicon.png');
    }

    public function test_putting_the_group_back_without_an_upload_leaves_the_icons_alone(): void
    {
        // Icons on disk, but the setting holds the shipped default: whatever
        // made them, this reset did not, and must not take them.
        $disk = Storage::disk('public');
        $disk->put('settings/icons/favicon.png', 'an icon');
        $disk->put('settings/icons/apple-touch-icon.png', 'an icon');

        $this->actingAs($this->admin())->delete('/settings/company')->assertRedirect();

        $disk->assertExists('settings/icons/favicon.png');
        $disk->assertExists('settings/icons/apple-touch-icon.png');
    }

    public function test_only_a_file_it_wrote_is_ever_deleted(): void
    {
        $disk = Storage::disk('public');
        $disk->put('products/keep.jpg', 'a product photo');

        // A climb out of the folder, the shipped default, an icon and a
        // nested path: none of them is an upload, so none is touched.
        SettingImage::delete('/storage/settings/../products/keep.jpg');
        SettingImage::delete('/storage/products/keep.jpg');
        SettingImage::delete('/images/brand-mark.png');

        $disk->assertExists('products/keep.jpg');

        $this->assertFalse(SettingImage::isUploaded('/storage/settings/icons/favicon.png'));
        $this->assertFalse(SettingImage::isUploaded('/images/brand-mark.png'));
        $this->assertFalse(SettingImage::isUploaded(''));
        $this->assertFalse(SettingImage::isUploaded(null));
    }
}
