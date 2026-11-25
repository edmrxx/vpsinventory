<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\AdjustmentPoster;
use App\Services\AuditService;
use Illuminate\Database\Seeder;

/**
 * Sample rows for the master screens, so they can be looked at with real
 * data in them.
 *
 * This seeder is NOT called from DatabaseSeeder. A normal setup must never
 * plant invented products. Run it by hand:
 *
 *     php artisan db:seed --class=SampleDataSeeder
 *
 * and take it away again with:
 *
 *     php artisan sample:clear
 *
 * Every row is matched on its SKU, code or name, so running it twice adds
 * nothing and overwrites no edit made on the screens.
 *
 * ---------------------------------------------------------------------------
 * The stock below arrives the way all stock does: through a posted opening
 * adjustment, which opens a FIFO layer for every product. Nothing here
 * writes `products.on_hand`.
 *
 * `sample:clear` removes the ledger rows along with the products.
 * ---------------------------------------------------------------------------
 */
class SampleDataSeeder extends Seeder
{
    /**
     * The categories, by name.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'Apparel' => 'Shirts, trousers, jackets and the like.',
        'Accessories' => 'Caps, socks and other small goods.',
    ];

    /**
     * The products, keyed by SKU.
     *
     * Everything here is taken from the Product Master mock-up, except the
     * socks: that row appears only in the Low Stock panel, which shows no
     * cost and no price, so its SKU, cost and selling price are invented.
     *
     * @var array<string, array<string, mixed>>
     */
    public const PRODUCTS = [
        'TSHIRT-BLK' => [
            'name' => 'T-Shirt (Black)',
            'category' => 'Apparel',
            'cost' => 300.00,
            'selling_price' => 500.00,
            'reorder_level' => 20,
            'on_hand' => 90,
        ],
        'TSHIRT-WHT' => [
            'name' => 'T-Shirt (White)',
            'category' => 'Apparel',
            'cost' => 300.00,
            'selling_price' => 500.00,
            'reorder_level' => 20,
            'on_hand' => 8,
        ],
        'CAP-NVY' => [
            'name' => 'Cap (Navy)',
            'category' => 'Accessories',
            'cost' => 200.00,
            'selling_price' => 300.00,
            'reorder_level' => 15,
            'on_hand' => 5,
        ],
        'JACKET-L' => [
            'name' => 'Jacket (L)',
            'category' => 'Apparel',
            'cost' => 600.00,
            'selling_price' => 1000.00,
            'reorder_level' => 10,
            'on_hand' => 3,
        ],
        'POLO-M' => [
            'name' => 'Polo Shirt (M)',
            'category' => 'Apparel',
            'cost' => 350.00,
            'selling_price' => 500.00,
            'reorder_level' => 15,
            'on_hand' => 6,
        ],
        'SOCKS-WHT' => [
            'name' => 'Socks',
            'category' => 'Accessories',
            'cost' => 80.00,
            'selling_price' => 150.00,
            'reorder_level' => 10,
            'on_hand' => 2,
        ],
    ];

    /**
     * The suppliers, keyed by code.
     *
     * ABC Trading Co. is the one named in the Purchase of Goods mock-up. The
     * other three are invented, with `.test` addresses and placeholder
     * numbers, so none of them points at a real business.
     *
     * @var array<string, array<string, string>>
     */
    public const SUPPLIERS = [
        'ABC-TRADING' => [
            'name' => 'ABC Trading Co.',
            'contact_person' => 'Maria Santos',
            'email' => 'sales@abc-trading.test',
            'phone' => '0917 000 0001',
            'address' => 'Quezon City, Metro Manila',
        ],
        'MNL-GARMENTS' => [
            'name' => 'Manila Garments Supply',
            'contact_person' => 'Ramon Cruz',
            'email' => 'orders@manila-garments.test',
            'phone' => '0917 000 0002',
            'address' => 'Divisoria, Manila',
        ],
        'NORTHPOINT' => [
            'name' => 'Northpoint Textiles',
            'contact_person' => 'Liza Reyes',
            'email' => 'hello@northpoint-textiles.test',
            'phone' => '0917 000 0003',
            'address' => 'San Fernando, Pampanga',
        ],
        'CEBU-ACC' => [
            'name' => 'Cebu Accessories Trading',
            'contact_person' => 'Noel Abad',
            'email' => 'sales@cebu-accessories.test',
            'phone' => '0917 000 0004',
            'address' => 'Mandaue City, Cebu',
        ],
    ];

    public function run(): void
    {
        app(AuditService::class)->withoutAuditing(fn () => $this->seed());
    }

    protected function seed(): void
    {
        // The products are counted in pieces, so the units must be in place.
        $unit = Unit::firstWhere('code', 'pcs');

        if (! $unit) {
            $this->call(MasterDataSeeder::class);
            $unit = Unit::firstWhere('code', 'pcs');
        }

        $categories = [];

        foreach (self::CATEGORIES as $name => $description) {
            $categories[$name] = Category::firstOrCreate(
                ['name' => $name],
                ['description' => $description, 'is_active' => true],
            );
        }

        $opening = [];

        foreach (self::PRODUCTS as $sku => $row) {
            $product = Product::firstOrCreate(
                ['sku' => $sku],
                [
                    'name' => $row['name'],
                    'category_id' => $categories[$row['category']]->id,
                    'unit_id' => $unit->id,
                    'cost' => $row['cost'],
                    'selling_price' => $row['selling_price'],
                    'reorder_level' => $row['reorder_level'],
                    'is_active' => true,
                ],
            );

            // Only a product this seeder has just made gets opening stock, so
            // a re-run never adds a second helping to a figure that has since
            // moved on its own.
            if ($product->wasRecentlyCreated) {
                $opening[] = [
                    'product_id' => $product->id,
                    'quantity' => $row['on_hand'],
                    'unit_cost' => $row['cost'],
                ];
            }
        }

        if ($opening !== []) {
            app(AdjustmentPoster::class)->openingBalance(
                $opening,
                null,
                'Opening stock for the sample products.',
            );
        }

        foreach (self::SUPPLIERS as $code => $row) {
            Supplier::firstOrCreate(['code' => $code], $row + ['is_active' => true]);
        }
    }
}
