<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\AuditService;
use App\Services\PurchasePoster;
use App\Services\PurchaseReturnPoster;
use App\Services\SalePoster;
use App\Services\SalesReturnPoster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A year of trading, so the reports have something to show.
 *
 * This is for a demonstration, not for a working installation. It is NOT
 * called from `DatabaseSeeder`, because a real setup must never plant
 * invented sales. Run it by hand, on a database with nothing in its ledger:
 *
 *     php artisan migrate:fresh --seed
 *     php artisan db:seed --class=DemoDataSeeder
 *     php artisan inventory:verify
 *
 * ---------------------------------------------------------------------------
 * Two rules shape everything below.
 *
 * **It posts, it does not insert.** Every purchase, sale, return and
 * adjustment goes through the same poster the screens use, so the FIFO
 * batches are real, the costs are what was really paid, and the ledger
 * balances. Writing rows straight into the tables would be far quicker and
 * would produce figures that look right and are not. `inventory:verify` is
 * the check that this was done properly.
 *
 * **It cannot backdate.** `InventoryLedger` refuses a document dated before
 * the last posted movement, because FIFO eats batches in posting order. So
 * history can only be built forwards, from an empty ledger, which is why
 * this seeder refuses to run when any movement already exists rather than
 * failing halfway through.
 * ---------------------------------------------------------------------------
 */
class DemoDataSeeder extends Seeder
{
    /** How many months of trading to build, ending today. */
    protected const MONTHS = 12;

    /** Every figure below is drawn from this, so a run can be repeated. */
    protected const SEED = 20261018;

    protected AdjustmentPoster $adjustments;

    protected PurchasePoster $purchases;

    protected SalePoster $sales;

    protected SalesReturnPoster $salesReturns;

    protected PurchaseReturnPoster $purchaseReturns;

    /** @var list<Product> */
    protected array $products = [];

    /** @var list<int> */
    protected array $supplierIds = [];

    /** @var list<int> */
    protected array $customerIds = [];

    /** Sales that could still have something come back. */
    protected array $returnableSales = [];

    /** Purchases that could still go back to the supplier. */
    protected array $returnablePurchases = [];

    protected int $counter = 0;

    public function run(): void
    {
        if (InventoryMovement::query()->exists()) {
            $this->command?->error('This database already has stock movements.');
            $this->command?->line('');
            $this->command?->warn('  A year of history can only be built forwards from an empty');
            $this->command?->warn('  ledger, because the ledger refuses a document dated before');
            $this->command?->warn('  the last one posted. Start from a clean database:');
            $this->command?->line('');
            $this->command?->line('      php artisan migrate:fresh --seed');
            $this->command?->line('      php artisan db:seed --class=DemoDataSeeder');
            $this->command?->line('');

            return;
        }

        mt_srand(self::SEED);

        $this->adjustments = app(AdjustmentPoster::class);
        $this->purchases = app(PurchasePoster::class);
        $this->sales = app(SalePoster::class);
        $this->salesReturns = app(SalesReturnPoster::class);
        $this->purchaseReturns = app(PurchaseReturnPoster::class);

        // Posted by somebody, so the documents name a person the way real
        // ones do. The masters are built without the trail: they are
        // scenery, not something a person did.
        $admin = User::where('username', 'admin')->first();

        if ($admin) {
            Auth::login($admin);
        }

        app(AuditService::class)->withoutAuditing(fn () => $this->masters());

        $this->command?->info('Masters ready. Posting a year of trading…');

        $this->trade();

        $this->report();
    }

    /* --------------------------------------------------------------- Masters */

    protected function masters(): void
    {
        $units = [];

        foreach ([
            ['pcs', 'Pieces'],
            ['box', 'Box'],
            ['pack', 'Pack'],
            ['set', 'Set'],
            ['pair', 'Pair'],
        ] as [$code, $name]) {
            $units[$code] = Unit::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true])->id;
        }

        $categories = [];

        foreach ([
            'Apparel', 'Accessories', 'Footwear', 'Bags', 'Headwear', 'Office Supplies',
        ] as $name) {
            $categories[$name] = Category::firstOrCreate(
                ['name' => $name],
                ['description' => "{$name} stocked for resale.", 'is_active' => true]
            )->id;
        }

        foreach ($this->supplierNames() as $index => $name) {
            $this->supplierIds[] = Supplier::firstOrCreate(
                ['name' => $name],
                [
                    'code' => sprintf('SUP-%04d', $index + 1),
                    'contact_person' => $this->pick($this->people()),
                    'phone' => '09'.mt_rand(100000000, 999999999),
                    'email' => 'orders@'.strtolower(str_replace([' ', '.', ','], '', $name)).'.ph',
                    'address' => $this->pick($this->cities()).', Philippines',
                    'is_active' => true,
                ]
            )->id;
        }

        $this->customerIds[] = Customer::firstOrCreate(
            ['name' => 'Walk-in Customer'],
            ['code' => 'CUS-0001', 'is_active' => true]
        )->id;

        foreach ($this->people() as $index => $name) {
            $this->customerIds[] = Customer::firstOrCreate(
                ['name' => $name],
                [
                    'code' => sprintf('CUS-%04d', $index + 2),
                    'phone' => '09'.mt_rand(100000000, 999999999),
                    'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
                    'address' => $this->pick($this->cities()).', Philippines',
                    'is_active' => true,
                ]
            )->id;
        }

        foreach ($this->catalogue() as [$sku, $name, $category, $unit, $cost, $price, $reorder]) {
            $this->products[] = Product::firstOrCreate(
                ['sku' => $sku],
                [
                    'name' => $name,
                    'description' => null,
                    'category_id' => $categories[$category],
                    'unit_id' => $units[$unit],
                    'cost' => $cost,
                    'selling_price' => $price,
                    'reorder_level' => $reorder,
                    'is_active' => true,
                ]
            );
        }
    }

    /* --------------------------------------------------------------- Trading */

    /**
     * A year, day by day, in the order it happened.
     *
     * The dates only ever move forwards, because the ledger will refuse a
     * document dated before the last one posted.
     */
    protected function trade(): void
    {
        $start = Carbon::today()->subMonths(self::MONTHS)->startOfMonth();
        $today = Carbon::today();

        $this->openingStock($start);

        $day = $start->copy()->addDay();
        $bar = $this->command?->getOutput()->createProgressBar($start->diffInDays($today));

        while ($day->lte($today)) {
            $this->dayOfTrading($day);

            $bar?->advance();
            $day->addDay();
        }

        $bar?->finish();
        $this->command?->line('');

        $this->someMistakes($today);
    }

    /**
     * A couple of documents entered in error, and voided.
     *
     * These come last, and are dated today, because a void reverses the
     * stock on the day it is voided rather than on the document's own date.
     * Voiding a document from last November would therefore write a
     * movement dated today, and every later document in this seeder would
     * then be refused as backdated. Posting them at the end avoids the
     * problem and is what really happens anyway: a mistake is noticed now.
     */
    protected function someMistakes(Carbon $today): void
    {
        $product = $this->pick($this->products);

        $purchase = $this->purchases->post([
            'supplier_id' => $this->pick($this->supplierIds),
            'purchase_date' => $today,
            'supplier_reference' => sprintf('INV-%s-%05d', $today->format('y'), ++$this->counter),
        ], [[
            'product_id' => $product->id,
            'quantity' => 12,
            'unit_cost' => $product->cost,
        ]]);

        $this->purchases->void($purchase, 'Duplicate of the supplier\'s earlier invoice.');

        // The best stocked product, not the one whose delivery was just
        // taken back: that one may have nothing left to sell.
        $stocked = Product::orderByDesc('on_hand')->first();

        if (! $stocked || $stocked->on_hand < 2) {
            return;
        }

        $sale = $this->sales->post([
            'customer_id' => $this->pick($this->customerIds),
            'sale_date' => $today,
            'discount' => 0,
        ], [[
            'product_id' => $stocked->id,
            'quantity' => 2,
            'unit_price' => $stocked->selling_price,
        ]]);

        $this->sales->void($sale, 'Encoded twice at the counter.');
    }

    /** The shelf on day one, the only way stock may arrive from nothing. */
    protected function openingStock(Carbon $date): void
    {
        $lines = [];

        foreach ($this->products as $product) {
            $lines[] = [
                'product_id' => $product->id,
                'quantity' => mt_rand(20, 120),
                'unit_cost' => $product->cost,
            ];
        }

        $this->adjustments->openingBalance($lines, $date, 'Counted at go-live.');
    }

    protected function dayOfTrading(Carbon $day): void
    {
        $sunday = $day->isSunday();

        // December is busy, Sunday is shut.
        if ($sunday) {
            return;
        }

        $busy = $day->month === 12 ? 2.0 : ($day->isSaturday() ? 1.5 : 1.0);

        // A delivery arrives roughly once a week.
        if (mt_rand(1, 6) === 1) {
            $this->aDelivery($day);
        }

        $howMany = (int) round(mt_rand(1, 4) * $busy);

        for ($i = 0; $i < $howMany; $i++) {
            $this->aSale($day);
        }

        // Now and then something comes back, or is miscounted.
        if (mt_rand(1, 18) === 1) {
            $this->aSalesReturn($day);
        }

        if (mt_rand(1, 45) === 1) {
            $this->aPurchaseReturn($day);
        }

        if (mt_rand(1, 30) === 1) {
            $this->anAdjustment($day);
        }
    }

    protected function aDelivery(Carbon $day): void
    {
        $lines = [];

        foreach ($this->some($this->products, mt_rand(2, 6)) as $product) {
            // The buying price drifts, so FIFO has real batches to work
            // through and the margin is not the same on every sale.
            $drift = mt_rand(92, 115) / 100;

            $lines[] = [
                'product_id' => $product->id,
                'quantity' => mt_rand(10, 60),
                'unit_cost' => round($product->cost * $drift, 2),
            ];
        }

        $purchase = $this->purchases->post([
            'supplier_id' => $this->pick($this->supplierIds),
            'purchase_date' => $day,
            'supplier_reference' => sprintf('INV-%s-%05d', $day->format('y'), ++$this->counter),
            'notes' => null,
        ], $lines);

        $this->returnablePurchases[] = $purchase->id;
    }

    protected function aSale(Carbon $day): void
    {
        $lines = [];

        foreach ($this->some($this->products, mt_rand(1, 4)) as $product) {
            $onHand = (int) Product::whereKey($product->id)->value('on_hand');

            if ($onHand < 2) {
                continue;
            }

            $lines[] = [
                'product_id' => $product->id,
                'quantity' => min($onHand, mt_rand(1, 6)),
                'unit_price' => $product->selling_price,
            ];
        }

        if ($lines === []) {
            return;
        }

        $subtotal = 0;

        foreach ($lines as $line) {
            $subtotal += $line['quantity'] * (float) $line['unit_price'];
        }

        // A discount on one sale in five, never more than a tenth.
        $discount = mt_rand(1, 5) === 1 ? round($subtotal * mt_rand(2, 10) / 100, 2) : 0;

        $sale = $this->sales->post([
            'customer_id' => $this->pick($this->customerIds),
            'sale_date' => $day,
            'discount' => $discount,
            'notes' => null,
        ], $lines);

        $this->returnableSales[] = $sale->id;
    }

    protected function aSalesReturn(Carbon $day): void
    {
        $sale = $this->aRecentOne($this->returnableSales, Sale::class);

        if (! $sale) {
            return;
        }

        $lines = [];

        foreach ($sale->lines as $line) {
            $returnable = $line->returnable();

            if ($returnable > 0 && mt_rand(1, 2) === 1) {
                $lines[] = ['sale_line_id' => $line->id, 'quantity' => min($returnable, mt_rand(1, 2))];
            }
        }

        if ($lines === []) {
            return;
        }

        $this->salesReturns->post([
            'sale_id' => $sale->id,
            'return_date' => $day,
            'reason' => $this->pick(['Faulty', 'Wrong item', 'Changed their mind', 'Damaged on arrival']),
            'notes' => null,
        ], $lines);
    }

    protected function aPurchaseReturn(Carbon $day): void
    {
        $purchase = $this->aRecentOne($this->returnablePurchases, Purchase::class);

        if (! $purchase || ! $purchase->isPosted()) {
            return;
        }

        $lines = [];

        foreach ($purchase->lines as $line) {
            $returnable = $line->returnable();

            if ($returnable > 0 && mt_rand(1, 2) === 1) {
                $lines[] = ['purchase_line_id' => $line->id, 'quantity' => min($returnable, mt_rand(1, 4))];
            }
        }

        if ($lines === []) {
            return;
        }

        $this->purchaseReturns->post([
            'purchase_id' => $purchase->id,
            'return_date' => $day,
            'reason' => $this->pick(['Damaged on arrival', 'Wrong item', 'Short delivery']),
            'notes' => null,
        ], $lines);
    }

    /** A breakage or a recount: stock no document explains. */
    protected function anAdjustment(Carbon $day): void
    {
        $product = $this->pick($this->products);
        $onHand = (int) Product::whereKey($product->id)->value('on_hand');

        $up = $onHand < 5 || mt_rand(1, 2) === 1;

        if (! $up && $onHand < 2) {
            return;
        }

        $this->adjustments->post(
            $up ? 'increase' : 'decrease',
            [[
                'product_id' => $product->id,
                'quantity' => $up ? mt_rand(1, 8) : min($onHand, mt_rand(1, 4)),
                'unit_cost' => $product->cost,
            ]],
            $day,
            $up ? 'Found in the stockroom' : $this->pick(['Breakage', 'Shrinkage', 'Recount']),
        );
    }

    /* ----------------------------------------------------------------- Tools */

    /**
     * One of the last few documents of a kind, with its lines loaded.
     *
     * Recent, because a customer brings something back days after buying
     * it, not a year later.
     */
    protected function aRecentOne(array &$ids, string $model): mixed
    {
        if ($ids === []) {
            return null;
        }

        $recent = array_slice($ids, -25);

        return $model::with('lines')->find($recent[array_rand($recent)]);
    }

    protected function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    /** @return list<mixed> */
    protected function some(array $items, int $howMany): array
    {
        $keys = (array) array_rand($items, min($howMany, count($items)));

        return array_map(fn ($key) => $items[$key], $keys);
    }

    protected function report(): void
    {
        $this->command?->info('Done.');
        $this->command?->table(
            ['What', 'How many'],
            [
                ['Products', Product::count()],
                ['Suppliers', Supplier::count()],
                ['Customers', Customer::count()],
                ['Purchases', Purchase::count()],
                ['Sales', Sale::count()],
                ['Stock movements', InventoryMovement::count()],
            ]
        );
        $this->command?->line('');
        $this->command?->warn('  Now run: php artisan inventory:verify');
    }

    /* ------------------------------------------------------------- The lists */

    /** @return list<string> */
    protected function supplierNames(): array
    {
        return [
            'ABC Trading Co.', 'Cebu Accessories Trading', 'Manila Textile House',
            'Davao Garments Supply', 'Northern Apparel Inc.', 'Pacific Footwear Corp.',
            'Island Bag Makers', 'Metro Office Depot', 'Sunrise Fabrics',
            'Golden Thread Supply', 'Visayas Distribution', 'Luzon Wholesale Co.',
        ];
    }

    /** @return list<string> */
    protected function people(): array
    {
        return [
            'Maria Santos', 'Jose Reyes', 'Ana Cruz', 'Pedro Bautista', 'Rosa Garcia',
            'Juan dela Cruz', 'Lourdes Mendoza', 'Ramon Villanueva', 'Carmen Aquino',
            'Antonio Ramos', 'Teresa Flores', 'Ricardo Castillo', 'Elena Torres',
            'Manuel Navarro', 'Sofia Domingo', 'Carlos Pascual', 'Isabel Rivera',
            'Fernando Lim', 'Grace Tan', 'Miguel Ocampo', 'Patricia Yu', 'Daniel Sy',
            'Angela Chua', 'Roberto Gomez', 'Cristina Padilla', 'Alfredo Salazar',
            'Vilma Rosales', 'Eduardo Mercado', 'Nina Alvarez', 'Oscar Fernandez',
            'Beatriz Santiago', 'Hector Morales', 'Luz Francisco', 'Rafael Guerrero',
            'Dolores Hidalgo', 'Samuel Agustin', 'Pilar Esteban', 'Victor Lazaro',
            'Corazon Valdez', 'Enrique Bernardo',
        ];
    }

    /** @return list<string> */
    protected function cities(): array
    {
        return [
            'Cebu City', 'Mandaue City', 'Lapu-Lapu City', 'Talisay City', 'Toledo City',
            'Danao City', 'Carcar City', 'Naga City', 'Bogo City', 'Consolacion',
        ];
    }

    /**
     * The catalogue: sku, name, category, unit, cost, price, reorder level.
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:float,5:float,6:int}>
     */
    protected function catalogue(): array
    {
        return [
            ['TSHIRT-BLK', 'T-Shirt (Black)', 'Apparel', 'pcs', 180.00, 320.00, 20],
            ['TSHIRT-WHT', 'T-Shirt (White)', 'Apparel', 'pcs', 180.00, 320.00, 20],
            ['TSHIRT-NVY', 'T-Shirt (Navy)', 'Apparel', 'pcs', 185.00, 330.00, 20],
            ['POLO-M', 'Polo Shirt (M)', 'Apparel', 'pcs', 290.00, 520.00, 15],
            ['POLO-L', 'Polo Shirt (L)', 'Apparel', 'pcs', 295.00, 530.00, 15],
            ['JACKET-L', 'Jacket (L)', 'Apparel', 'pcs', 580.00, 980.00, 10],
            ['JACKET-XL', 'Jacket (XL)', 'Apparel', 'pcs', 600.00, 1020.00, 10],
            ['HOODIE-GRY', 'Hoodie (Grey)', 'Apparel', 'pcs', 480.00, 850.00, 12],
            ['SHORTS-KHA', 'Shorts (Khaki)', 'Apparel', 'pcs', 240.00, 430.00, 15],
            ['JEANS-32', 'Jeans (32)', 'Apparel', 'pcs', 520.00, 940.00, 10],
            ['CAP-NVY', 'Cap (Navy)', 'Headwear', 'pcs', 205.00, 300.00, 15],
            ['CAP-BLK', 'Cap (Black)', 'Headwear', 'pcs', 205.00, 300.00, 15],
            ['BUCKET-TAN', 'Bucket Hat (Tan)', 'Headwear', 'pcs', 230.00, 390.00, 10],
            ['BEANIE-CHR', 'Beanie (Charcoal)', 'Headwear', 'pcs', 150.00, 280.00, 10],
            ['SOCKS-WHT', 'Socks (White)', 'Accessories', 'pair', 60.00, 120.00, 30],
            ['SOCKS-BLK', 'Socks (Black)', 'Accessories', 'pair', 60.00, 120.00, 30],
            ['BELT-BRN', 'Belt (Brown)', 'Accessories', 'pcs', 190.00, 350.00, 12],
            ['SCARF-RED', 'Scarf (Red)', 'Accessories', 'pcs', 140.00, 260.00, 10],
            ['WALLET-BLK', 'Wallet (Black)', 'Accessories', 'pcs', 310.00, 580.00, 10],
            ['SUNGLASS-01', 'Sunglasses', 'Accessories', 'pcs', 260.00, 490.00, 10],
            ['SNEAK-42', 'Sneakers (42)', 'Footwear', 'pair', 890.00, 1550.00, 8],
            ['SNEAK-43', 'Sneakers (43)', 'Footwear', 'pair', 890.00, 1550.00, 8],
            ['SANDAL-41', 'Sandals (41)', 'Footwear', 'pair', 320.00, 590.00, 10],
            ['LOAFER-42', 'Loafers (42)', 'Footwear', 'pair', 760.00, 1340.00, 6],
            ['BACKPACK-01', 'Backpack', 'Bags', 'pcs', 640.00, 1150.00, 8],
            ['TOTE-CNV', 'Tote Bag (Canvas)', 'Bags', 'pcs', 210.00, 390.00, 15],
            ['DUFFEL-01', 'Duffel Bag', 'Bags', 'pcs', 780.00, 1380.00, 6],
            ['NOTEBOOK-A5', 'Notebook (A5)', 'Office Supplies', 'pcs', 55.00, 110.00, 40],
            ['PEN-BLU', 'Ballpen (Blue)', 'Office Supplies', 'box', 120.00, 220.00, 25],
            ['FOLDER-L', 'Folder (Long)', 'Office Supplies', 'pack', 85.00, 160.00, 25],
        ];
    }
}
