<?php

namespace Database\Seeders;

use App\Models\InventoryLayer;
use App\Models\Product;
use App\Services\AdjustmentPoster;
use Illuminate\Database\Seeder;

/**
 * SAMPLE stock for the hardware starter catalogue, so a sale can be tried
 * at the till before the client counts their real shelves.
 *
 *     php artisan db:seed --class=HardwareOpeningStockSeeder
 *
 * It posts ONE Opening stock adjustment through AdjustmentPoster, the same
 * way the Adjustments screen does: the batches, the stock card and the audit
 * row all come from that document. Nothing here writes `products.on_hand`.
 *
 * Each product gets four times its reorder level, so the amount suits the
 * unit (bags of cement, metres of wire). The products in LOW get half of it
 * instead, at or under the reorder level, so the Low Stock panel has
 * something to show. The unit cost is the product's own cost, so a sale
 * earns the margin the price list says it should.
 *
 * **Only a product that has never held stock gets any**, so running it twice
 * adds nothing.
 *
 * **This is not the client's stock.** Before go-live `php artisan data:reset`
 * takes it away together with every sale tried against it, and the real
 * opening stock goes in through Adjustments.
 */
class HardwareOpeningStockSeeder extends Seeder
{
    /** Each product gets this many times its reorder level. */
    public const MULTIPLE = 4;

    /**
     * Left at half the reorder level, so they show as low stock.
     *
     * @var list<string>
     */
    public const LOW = [
        'CEM-CHB-4',
        'STL-RB-10',
        'NAI-CMN-3',
        'PNT-LTX-WHT-G',
        'PLB-PVC-12',
        'WIR-THHN-35',
        'SWO-OUT-DPX',
        'LGT-LED-9W',
    ];

    public const NOTE = 'SAMPLE opening stock for trying the till. Not real stock: data:reset removes it before go-live.';

    public function run(): void
    {
        $lines = Product::query()
            ->whereIn('sku', array_keys(HardwareProductSeeder::PRODUCTS))
            ->whereNotIn('id', InventoryLayer::query()->select('product_id'))
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product) => [
                'product_id' => $product->id,
                'quantity' => self::quantityFor($product),
                'unit_cost' => $product->cost,
            ])
            ->all();

        if ($lines === []) {
            $this->command?->info('Every hardware product already has stock. Nothing added.');

            return;
        }

        $adjustment = app(AdjustmentPoster::class)->openingBalance($lines, null, self::NOTE);

        $this->command?->info(sprintf(
            'Posted %s: sample stock for %d product(s), worth ₱%s.',
            $adjustment->reference,
            count($lines),
            number_format((float) $adjustment->total_cost, 2),
        ));
    }

    public static function quantityFor(Product $product): int
    {
        $reorder = max(1, (int) $product->reorder_level);

        return in_array($product->sku, self::LOW, true)
            ? max(1, intdiv($reorder, 2))
            : $reorder * self::MULTIPLE;
    }
}
