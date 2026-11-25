<?php

namespace Tests\Feature\Seeders;

use App\Models\Product;
use Database\Seeders\HardwareProductPhotoSeeder;
use Database\Seeders\HardwareProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HardwareProductPhotoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HardwareProductSeeder::class);
    }

    public function test_every_product_in_the_catalogue_has_a_picture_shipped(): void
    {
        foreach (array_keys(HardwareProductSeeder::PRODUCTS) as $sku) {
            $this->assertFileExists(HardwareProductPhotoSeeder::DIRECTORY."/{$sku}.webp");
        }

        // And no picture is left over for a product that is gone.
        $this->assertCount(
            count(HardwareProductSeeder::PRODUCTS),
            glob(HardwareProductPhotoSeeder::DIRECTORY.'/*.webp')
        );
    }

    public function test_each_product_gets_its_own_picture_saved_like_an_upload(): void
    {
        $this->seed(HardwareProductPhotoSeeder::class);

        $this->assertSame(0, Product::whereNull('photo_path')->count());

        $paths = Product::pluck('photo_path');
        $this->assertCount(count(HardwareProductSeeder::PRODUCTS), $paths->unique());

        foreach ($paths as $path) {
            $this->assertStringStartsWith('products/', $path);
            Storage::disk('public')->assertExists($path);
        }

        $this->assertDatabaseCount('audit_trails', 0);
    }

    public function test_a_real_photo_is_never_replaced(): void
    {
        $real = Product::firstWhere('sku', 'LGT-LED-9W');
        $real->update(['photo_path' => 'products/real-bulb.jpg']);

        $this->seed(HardwareProductPhotoSeeder::class);
        $first = Product::firstWhere('sku', 'HTL-HAM-16')->photo_path;

        $this->seed(HardwareProductPhotoSeeder::class);

        $this->assertSame('products/real-bulb.jpg', $real->fresh()->photo_path);
        $this->assertSame($first, Product::firstWhere('sku', 'HTL-HAM-16')->photo_path);
        $this->assertCount(count(HardwareProductSeeder::PRODUCTS) - 1, Storage::disk('public')->allFiles('products'));
    }
}
