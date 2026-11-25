<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Services\AuditService;
use App\Support\ProductPhoto;
use Illuminate\Database\Seeder;

/**
 * A sample picture for each product of the hardware starter catalogue.
 *
 *     php artisan db:seed --class=HardwareProductPhotoSeeder
 *
 * The pictures are illustrated tiles — a gradient in the category's colour,
 * an icon for the product, a hang tag with its size, and a "SAMPLE PHOTO"
 * badge — so nobody mistakes one for a photograph of the real item. The
 * name is left off: every screen that shows a photo prints it underneath,
 * and the till crops the square to 4:3, so everything sits in the middle. They sit in `database/seeders/photos/hardware`,
 * one per SKU, and are saved through ProductPhoto the way an upload is.
 *
 * **Only a product with no photo gets one.** A real photo uploaded on the
 * Edit dialog is never replaced, so running this twice changes nothing and
 * running it after the client has started adding photos is safe.
 *
 * Needs `php artisan storage:link`, like every product photo.
 */
class HardwareProductPhotoSeeder extends Seeder
{
    public const DIRECTORY = __DIR__.'/photos/hardware';

    public function run(): void
    {
        $added = 0;

        app(AuditService::class)->withoutAuditing(function () use (&$added) {
            Product::query()
                ->whereIn('sku', array_keys(HardwareProductSeeder::PRODUCTS))
                ->whereNull('photo_path')
                ->orderBy('id')
                ->each(function (Product $product) use (&$added) {
                    $file = self::DIRECTORY.'/'.$product->sku.'.webp';

                    if (! is_file($file)) {
                        return;
                    }

                    $product->update(['photo_path' => ProductPhoto::storeFromFile($file)]);
                    $added++;
                });
        });

        $this->command?->info("Sample photos added to {$added} product(s).");
    }
}
