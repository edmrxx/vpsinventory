<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Support\ProductPhoto;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A picture of the product.
 *
 * The column holds a path; the file lives on the `public` disk. What these
 * tests are really guarding is the rubbish nobody sees: a replaced photo
 * whose old file is never removed, a deleted product whose picture stays
 * on the server for ever, and an SVG getting in, which would be script
 * served from this system's own domain.
 */
class ProductPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Storage::fake('public');

        $this->category = $this->unaudited(fn () => Category::factory()->create());
        $this->unit = $this->unaudited(fn () => Unit::factory()->create());
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'TSHIRT-BLK',
            'name' => 'T-Shirt (Black)',
            'description' => null,
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'cost' => '100.00',
            'selling_price' => '250.00',
            'reorder_level' => 5,
            'is_active' => true,
        ], $overrides);
    }

    /* ------------------------------------------------------------ Uploading */

    public function test_a_product_can_be_created_with_a_photo(): void
    {
        $this->actingAs($this->admin())
            ->post('/products', $this->payload([
                'photo' => UploadedFile::fake()->image('shirt.jpg', 600, 400),
            ]))
            ->assertRedirect();

        $product = Product::firstOrFail();

        $this->assertNotNull($product->photo_path);
        Storage::disk('public')->assertExists($product->photo_path);

        // The name the browser sent is never used: it is somebody else's
        // text, and two uploads called shirt.jpg must not collide.
        $this->assertStringStartsWith('products/', $product->photo_path);
        $this->assertStringNotContainsString('shirt', $product->photo_path);
    }

    public function test_a_product_without_a_photo_stores_nothing(): void
    {
        $this->actingAs($this->admin())
            ->post('/products', $this->payload())
            ->assertRedirect();

        $this->assertNull(Product::firstOrFail()->photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /* ----------------------------------------------------------- Replacing */

    public function test_replacing_a_photo_removes_the_old_file(): void
    {
        $product = $this->unaudited(fn () => Product::factory()->create([
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'photo_path' => UploadedFile::fake()->image('old.jpg')->store('products', 'public'),
        ]));

        $old = $product->photo_path;

        $this->actingAs($this->admin())
            ->post("/products/{$product->id}", $this->payload([
                '_method' => 'put',
                'sku' => $product->sku,
                'photo' => UploadedFile::fake()->image('new.jpg'),
            ]))
            ->assertRedirect();

        $product->refresh();

        $this->assertNotSame($old, $product->photo_path);
        Storage::disk('public')->assertExists($product->photo_path);
        Storage::disk('public')->assertMissing($old);

        // Exactly one file, not two. A photo nobody can reach is still
        // paid for in disk space and in every backup.
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_an_edit_that_sends_no_file_keeps_the_photo(): void
    {
        $product = $this->unaudited(fn () => Product::factory()->create([
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'photo_path' => UploadedFile::fake()->image('keep.jpg')->store('products', 'public'),
        ]));

        $kept = $product->photo_path;

        $this->actingAs($this->admin())
            ->put("/products/{$product->id}", $this->payload([
                'sku' => $product->sku,
                'name' => 'A new name',
            ]))
            ->assertRedirect();

        $product->refresh();

        $this->assertSame('A new name', $product->name);
        $this->assertSame($kept, $product->photo_path);
        Storage::disk('public')->assertExists($kept);
    }

    public function test_the_photo_can_be_taken_off(): void
    {
        $product = $this->unaudited(fn () => Product::factory()->create([
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'photo_path' => UploadedFile::fake()->image('gone.jpg')->store('products', 'public'),
        ]));

        $old = $product->photo_path;

        $this->actingAs($this->admin())
            ->put("/products/{$product->id}", $this->payload([
                'sku' => $product->sku,
                'remove_photo' => true,
            ]))
            ->assertRedirect();

        $this->assertNull($product->refresh()->photo_path);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_deleting_a_product_removes_its_photo(): void
    {
        $product = $this->unaudited(fn () => Product::factory()->create([
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'photo_path' => UploadedFile::fake()->image('bye.jpg')->store('products', 'public'),
        ]));

        $path = $product->photo_path;

        $this->actingAs($this->admin())
            ->delete("/products/{$product->id}")
            ->assertRedirect();

        Storage::disk('public')->assertMissing($path);
    }

    /* ---------------------------------------------------------- What is let in */

    public function test_an_svg_is_refused(): void
    {
        // An SVG is XML and can carry script. Served from this system's own
        // domain, that script would run as this system.
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->actingAs($this->admin())
            ->post('/products', $this->payload(['photo' => $svg]))
            ->assertSessionHasErrors('photo');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/products', $this->payload([
                'photo' => UploadedFile::fake()->create('prices.pdf', 40, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('photo');
    }

    public function test_a_photo_over_two_megabytes_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('/products', $this->payload([
                'photo' => UploadedFile::fake()->image('huge.jpg')->size(2049),
            ]))
            ->assertSessionHasErrors('photo');
    }

    /* ------------------------------------------------------------- Shrinking */

    public function test_a_large_photograph_is_shrunk(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed, so nothing is resized.');
        }

        // 2400 x 1800 rather than a full twelve megapixels. GD opens a
        // picture uncompressed, so a 12 MP photograph needs about 48 MB and
        // whether that fits depends on how much of the 128 MB the rest of
        // the suite has already taken — which would make this test pass or
        // fail by accident. What is being proved here is that a large
        // picture is shrunk, not how large "large" may be.
        $this->actingAs($this->admin())
            ->post('/products', $this->payload([
                'photo' => UploadedFile::fake()->image('phone.jpg', 2400, 1800),
            ]))
            ->assertRedirect();

        $file = Storage::disk('public')->path(Product::firstOrFail()->photo_path);
        [$width, $height] = getimagesize($file);

        $this->assertSame(ProductPhoto::MAX_EDGE, $width);
        $this->assertSame(900, $height, 'The shape must be kept.');
    }

    public function test_a_small_photograph_is_left_alone(): void
    {
        $this->actingAs($this->admin())
            ->post('/products', $this->payload([
                'photo' => UploadedFile::fake()->image('small.jpg', 300, 200),
            ]))
            ->assertRedirect();

        $file = Storage::disk('public')->path(Product::firstOrFail()->photo_path);

        $this->assertSame([300, 200], array_slice(getimagesize($file), 0, 2));
    }

    /**
     * A landscape JPEG carrying "Orientation = 6", the way a phone writes a
     * portrait photograph. GD cannot write EXIF, and
     * `UploadedFile::fake()->image()` carries none, so the tag is spliced
     * in by hand — which is the only way to test the bug it causes.
     */
    protected function phonePhoto(int $width, int $height, int $orientation = 6): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phone').'.jpg';

        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 90, 160));
        imagejpeg($image, $path, 92);
        imagedestroy($image);

        $jpeg = file_get_contents($path);

        // One IFD0 entry: Orientation (0x0112), SHORT, count 1.
        $entry = "\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00";
        $tiff = "\x4D\x4D\x00\x2A\x00\x00\x00\x08".pack('n', 1).$entry."\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00".$tiff;

        file_put_contents(
            $path,
            "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2)
        );

        return $path;
    }

    public function test_a_photograph_taken_on_a_phone_is_turned_upright(): void
    {
        if (! function_exists('exif_read_data') || ! function_exists('imagerotate')) {
            $this->markTestSkipped('This PHP cannot read EXIF or turn an image.');
        }

        // 800 wide by 600 tall on disk, but the camera says "turn it", so
        // what the photographer saw is 600 by 800. A browser obeys that tag
        // and GD does not, which is why the dialog preview looked upright
        // and the saved copy came out on its side.
        $file = $this->phonePhoto(800, 600);

        $this->actingAs($this->admin())
            ->post('/products', $this->payload([
                'photo' => new UploadedFile($file, 'phone.jpg', 'image/jpeg', null, true),
            ]))
            ->assertRedirect();

        $stored = Storage::disk('public')->path(Product::firstOrFail()->photo_path);
        [$width, $height] = getimagesize($stored);

        $this->assertSame(600, $width, 'The picture was not turned upright.');
        $this->assertSame(800, $height);

        // Written out fresh, so the camera's notes — the place it was taken
        // among them — do not travel with the product.
        $exif = @exif_read_data($stored);

        $this->assertArrayNotHasKey('Orientation', $exif ?: []);
    }

    /* -------------------------------------------------------------- Payloads */

    public function test_every_screen_is_told_where_the_photo_is(): void
    {
        $admin = $this->admin();

        $product = $this->unaudited(fn () => Product::factory()->create([
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
            'photo_path' => UploadedFile::fake()->image('seen.jpg')->store('products', 'public'),
        ]));

        $url = $product->photoUrl();

        // A path from the site root, so no screen depends on APP_URL.
        $this->assertNotNull($url);
        $this->assertStringStartsWith('/storage/products/', $url);

        $this->actingAs($admin)
            ->get('/products')
            ->assertInertia(fn ($page) => $page->where('products.data.0.photo_url', $url));

        $this->actingAs($admin)
            ->get('/stock')
            ->assertInertia(fn ($page) => $page->where('stock.data.0.photo_url', $url));

        $this->actingAs($admin)
            ->getJson("/products/{$product->id}/preview")
            ->assertOk()
            ->assertJsonPath('product.photo_url', $url);

        // The API sends the absolute address instead: a phone cannot
        // resolve a path from the site root on its own.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.photo_url', url($url));
    }

    public function test_a_product_with_no_photo_sends_null(): void
    {
        $product = $this->unaudited(fn () => Product::factory()->create([
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
        ]));

        $this->assertNull($product->photoUrl());

        $this->actingAs($this->admin())
            ->getJson("/products/{$product->id}/preview")
            ->assertOk()
            ->assertJsonPath('product.photo_url', null);
    }

    /* ---------------------------------------------------------- Who may do it */

    public function test_a_user_without_the_update_permission_cannot_change_a_photo(): void
    {
        $product = $this->unaudited(fn () => Product::factory()->create([
            'category_id' => $this->category->id,
            'unit_id' => $this->unit->id,
        ]));

        $viewer = $this->unaudited(
            fn () => User::factory()->withPermissions('products.view')->create()
        );

        $this->actingAs($viewer)
            ->put("/products/{$product->id}", $this->payload([
                'sku' => $product->sku,
                'photo' => UploadedFile::fake()->image('nope.jpg'),
            ]))
            ->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
