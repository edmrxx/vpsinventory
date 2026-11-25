<?php

namespace Database\Seeders;

use App\Exceptions\InventoryException;
use App\Exceptions\PaymentException;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Services\AuditService;
use App\Services\DashboardMetrics;
use App\Services\InventoryMetrics;
use App\Services\PurchasePoster;
use App\Services\PurchaseReturnPoster;
use App\Services\SalePoster;
use App\Services\SalesReturnPoster;
use App\Support\CustomerCredit;
use App\Support\SaleTotals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * A year of hardware trading, 2 January to today, for a client presentation.
 *
 * NOT called from `DatabaseSeeder`, and never for a working installation:
 * these are invented sales. It runs on the hardware catalogue already in the
 * database (the products, their photos, categories, units and suppliers) and
 * refuses to start while the ledger holds anything. Run it by hand:
 *
 *     php artisan db:seed --class=PresentationDataSeeder
 *     php artisan inventory:verify
 *
 * Take it away before go-live with `php artisan data:reset`, then delete the
 * sample cashier (`cashier`), which a reset keeps because it keeps users.
 *
 * ---------------------------------------------------------------------------
 * Like `DemoDataSeeder`, it **posts, it does not insert**: every document goes
 * through the poster the screens use, so the batches, the cost of each sale
 * and the ledger are real, and `inventory:verify` is the proof. And it
 * **cannot backdate**: the days are walked forwards, and the two voids come
 * last, dated today, because a void moves stock on the day it is voided.
 *
 * What it adds on top of that one: the payment methods (cash, GCash, Maya,
 * card, bank, a few split), references where a method needs one, sales by
 * the kilo and the metre, a box sold in its other unit, sales on account to
 * customers with credit from October, a supplier for each category, seasons
 * (fans and construction in summer, roofing and pumps in the rains), and a
 * cashier, so the Staff filter has three people to choose between.
 *
 * The trail is left out: thousands of rows stamped today would read as if
 * somebody had keyed a year in one morning. Each document's own posted time
 * is set to its day, so the screens read like real trading.
 * ---------------------------------------------------------------------------
 */
class PresentationDataSeeder extends Seeder
{
    protected const SEED = 20260102;

    protected const START = '2026-01-02';

    /** Credit sales start here, so the unpaid list is recent, not a year old. */
    protected const CREDIT_FROM = '2026-10-01';

    /** Closed: Sundays, and these. */
    protected const HOLIDAYS = ['2026-04-02', '2026-04-03', '2026-04-09', '2026-05-01', '2026-06-12', '2026-08-21', '2026-08-31'];

    /** How busy each month is, against an ordinary day. */
    protected const SEASON = [1 => 0.85, 2 => 0.95, 3 => 1.2, 4 => 1.3, 5 => 1.3, 6 => 1.0, 7 => 0.8, 8 => 0.8, 9 => 0.85, 10 => 1.0, 11 => 1.1, 12 => 1.4];

    protected SalePoster $sales;

    protected PurchasePoster $purchases;

    protected SalesReturnPoster $salesReturns;

    protected PurchaseReturnPoster $purchaseReturns;

    protected AdjustmentPoster $adjustments;

    /** @var array<int, array{id:int, name:string, category:string, unit:string, decimals:bool, cost:float, price:float, supplier:int, target:int}> */
    protected array $products = [];

    /** @var array<int, Customer> named customers, keyed by id */
    protected array $customers = [];

    protected int $walkInId;

    /** @var array<string, User> */
    protected array $staff = [];

    /** @var array<string, int> payment method ids by name */
    protected array $methods = [];

    /** The box sold in its other unit: product id => [unit id, factor, price]. */
    protected array $otherUnits = [];

    protected array $recentSales = [];

    protected array $recentPurchases = [];

    protected array $tally = ['sales' => 0, 'purchases' => 0, 'sales returns' => 0, 'purchase returns' => 0, 'adjustments' => 0, 'voids' => 0, 'skipped' => 0];

    protected Carbon $clock;

    public function run(): void
    {
        if (InventoryMovement::query()->exists()) {
            $this->command?->error('The ledger is not empty. A year can only be built forwards from nothing.');

            return;
        }

        if (Product::count() === 0) {
            $this->command?->error('There are no products. Load the hardware catalogue first.');

            return;
        }

        mt_srand(self::SEED);

        $this->sales = app(SalePoster::class);
        $this->purchases = app(PurchasePoster::class);
        $this->salesReturns = app(SalesReturnPoster::class);
        $this->purchaseReturns = app(PurchaseReturnPoster::class);
        $this->adjustments = app(AdjustmentPoster::class);

        app(AuditService::class)->withoutAuditing(function () {
            $this->people();
            $this->catalogue();
            $this->trade();
        });

        app(InventoryMetrics::class)->flush();
        app(DashboardMetrics::class)->flush();

        $this->command?->table(['Posted', 'How many'], collect($this->tally)->map(fn ($n, $k) => [$k, number_format($n)])->values()->all());
    }

    /* ---------------------------------------------------------------- People */

    protected function people(): void
    {
        $this->walkInId = (int) Customer::where('is_walk_in', true)->value('id');

        // [code, name, contact, city, credit limit, terms in days]
        $list = [
            ['CUST-003', 'RJ Builders & Construction', 'Rodel Jimenez', 'Mandaue City', 150000, 30],
            ['CUST-004', 'Villanueva Construction Services', 'Arnel Villanueva', 'Cebu City', 100000, 30],
            ['CUST-005', 'Bautista Electrical Contractor', 'Mark Bautista', 'Lapu-Lapu City', 80000, 30],
            ['CUST-006', 'Golden Sun Realty Development', 'Grace Uy', 'Talisay City', 250000, 45],
            ['CUST-007', 'Mendoza Plumbing Works', 'Ramon Mendoza', 'Consolacion', 50000, 15],
            ['CUST-008', 'Reyes Furniture Shop', 'Liza Reyes', 'Liloan', 30000, 15],
            ['CUST-009', 'Lim Hardware & Auto Supply', 'Henry Lim', 'Danao City', 60000, 30],
            ['CUST-010', 'Ocampo Renovations', 'Joy Ocampo', 'Mandaue City', 40000, 15],
            ['CUST-011', 'Del Rosario Welding Shop', 'Boy del Rosario', 'Cebu City', 20000, 7],
            ['CUST-012', 'St. Joseph Parish', 'Fr. Dennis Abad', 'Mandaue City', 0, 0],
            ['CUST-013', 'Cruz Family Residence', 'Celia Cruz', 'Talisay City', 0, 0],
            ['CUST-014', 'Tan Building Maintenance', 'Kevin Tan', 'Cebu City', 0, 0],
            ['CUST-015', 'Garcia Fishpond Farm', 'Nonoy Garcia', 'Cordova', 0, 0],
            ['CUST-016', 'Barangay San Isidro Hall', 'Kap. Elmer Sison', 'Talisay City', 0, 0],
        ];

        foreach ($list as [$code, $name, $contact, $city, $limit, $terms]) {
            $customer = Customer::firstOrNew(['code' => $code]);
            $customer->forceFill([
                'name' => $name,
                'contact_person' => $contact,
                'phone' => '09'.mt_rand(170000000, 999999999),
                'email' => strtolower(preg_replace('/[^a-z]+/i', '', $name)).'@example.ph',
                'address' => "{$city}, Cebu",
                'is_active' => true,
                'is_walk_in' => false,
                'credit_limit' => $limit,
                'payment_terms_days' => $terms,
            ])->save();
        }

        foreach (Customer::where('is_walk_in', false)->where('is_active', true)->get() as $customer) {
            $this->customers[$customer->id] = $customer;
        }

        $cashier = User::firstOrNew(['username' => 'cashier']);

        if (! $cashier->exists) {
            $cashier->forceFill([
                'name' => 'Maria Santos',
                'email' => 'maria.santos@example.ph',
                'password' => Hash::make('cashier123'),
                'is_active' => true,
            ])->save();

            if ($role = Role::where('name', 'Sales Encoder')->first()) {
                $cashier->assignRole($role);
            }
        }

        $this->staff = [
            'admin' => User::where('username', 'admin')->firstOrFail(),
            'encoder' => User::where('username', 'encoder')->firstOrFail(),
            'cashier' => $cashier,
        ];

        $this->methods = DB::table('payment_methods')->where('is_active', true)->pluck('id', 'name')->all();
    }

    /* ------------------------------------------------------------- Catalogue */

    protected function catalogue(): void
    {
        $suppliers = DB::table('suppliers')->pluck('id', 'code')->all();

        $byCategory = [
            'Cement & Aggregates' => 'CEBU-CEMENT', 'Tiles & Flooring' => 'CEBU-CEMENT',
            'Steel & Rebar' => 'VISAYAS-STEEL', 'Roofing & Gutters' => 'VISAYAS-STEEL',
            'Wires & Cables' => 'METRO-WIRE', 'Conduits & Boxes' => 'METRO-WIRE',
            'Switches & Outlets' => 'BRIGHTLINE', 'Circuit Protection' => 'BRIGHTLINE', 'Lighting' => 'BRIGHTLINE',
            'Fans & Ventilation' => 'BRIGHTLINE', 'Electrical Accessories' => 'BRIGHTLINE',
            'Plumbing' => 'AQUAFLOW', 'Plumbing Fixtures' => 'AQUAFLOW', 'Water Pumps & Tanks' => 'AQUAFLOW',
            'Paints & Coatings' => 'COLORMAX', 'Painting Supplies' => 'COLORMAX',
            'Lumber & Plywood' => 'SANTOS-LUMBER',
            'Nails, Screws & Fasteners' => 'RJ-NAILS',
            'Hand Tools' => 'TOOLHUB', 'Power Tools & Accessories' => 'TOOLHUB', 'Safety & PPE' => 'TOOLHUB',
        ];

        $fallback = $suppliers['DELACRUZ-GEN'] ?? reset($suppliers);

        $rows = DB::table('products')
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.is_active', true)
            ->orderBy('products.id')
            ->get(['products.id', 'products.name', 'categories.name as category', 'units.code as unit', 'units.allows_decimals', 'products.cost', 'products.selling_price']);

        foreach ($rows as $row) {
            $price = (float) $row->selling_price;

            $this->products[$row->id] = [
                'id' => (int) $row->id,
                'name' => $row->name,
                'category' => $row->category,
                'unit' => $row->unit,
                'decimals' => (bool) $row->allows_decimals,
                'cost' => (float) $row->cost,
                'price' => $price,
                'supplier' => (int) ($suppliers[$byCategory[$row->category] ?? ''] ?? $fallback),
                'target' => $this->shelfLevel($row->unit, $price),
            ];
        }

        foreach (DB::table('product_units')->get() as $unit) {
            if (isset($this->products[$unit->product_id]) && $unit->selling_price !== null) {
                $this->otherUnits[$unit->product_id] = [(int) $unit->unit_id, (float) $unit->factor, (float) $unit->selling_price];
            }
        }
    }

    /** How much of a product a hardware store this size keeps on the shelf. */
    protected function shelfLevel(string $unit, float $price): int
    {
        return match (true) {
            $unit === 'm' => mt_rand(400, 900),
            $unit === 'kg' => mt_rand(80, 200),
            $unit === 'cu.m' => mt_rand(20, 40),
            $price <= 30 => mt_rand(300, 800),
            $price <= 100 => mt_rand(80, 200),
            $price <= 300 => mt_rand(40, 90),
            $price <= 1000 => mt_rand(20, 45),
            $price <= 3000 => mt_rand(8, 20),
            default => mt_rand(4, 10),
        };
    }

    /* --------------------------------------------------------------- Trading */

    protected function trade(): void
    {
        $day = Carbon::parse(self::START);
        $today = Carbon::today();

        $this->openingStock($day);

        $bar = $this->command?->getOutput()->createProgressBar($day->diffInDays($today) + 1);

        while ($day->lte($today)) {
            if (! $day->isSunday() && ! in_array($day->toDateString(), self::HOLIDAYS, true)) {
                $this->dayOfTrading($day->copy(), $day->isSameDay($today));
            }

            $bar?->advance();
            $day->addDay();
        }

        $bar?->finish();
        $this->command?->newLine();

        $this->mistakes($today);
    }

    protected function openingStock(Carbon $day): void
    {
        $this->startClock($day, 7, 0);

        $lines = [];

        foreach ($this->products as $product) {
            $lines[] = ['product_id' => $product['id'], 'quantity' => $product['target'], 'unit_cost' => $product['cost']];
        }

        $this->as('admin');
        $adjustment = $this->adjustments->openingBalance($lines, $day, 'Counted on the first day of the year.');
        $this->stamp($adjustment);
        $this->tally['adjustments']++;
    }

    protected function dayOfTrading(Carbon $day, bool $isToday): void
    {
        $this->startClock($day, 7, mt_rand(30, 50));

        // Deliveries come in first thing on Mondays and Thursdays.
        if ($day->isMonday() || $day->isThursday()) {
            $this->restock($day);
        }

        $busy = self::SEASON[$day->month] * ($day->isSaturday() ? 1.3 : 1.0) * (1 + 0.08 * $day->dayOfYear / 365);
        $howMany = (int) round(mt_rand(8, 14) * $busy);

        // Today is still young: only the morning has happened.
        if ($isToday) {
            $howMany = min($howMany, 9);
        }

        for ($i = 0; $i < $howMany; $i++) {
            $this->aSale($day, $isToday);

            if (mt_rand(1, 100) <= 4) {
                $this->aSalesReturn($day);
            }
        }

        if (mt_rand(1, 100) <= 6) {
            $this->aPurchaseReturn($day);
        }

        if (mt_rand(1, 100) <= 9) {
            $this->anAdjustment($day);
        }

        // The quarter's stock count finds what went missing.
        if (in_array($day->format('m-d'), ['03-31', '06-30', '09-30'], true)) {
            $this->stockCount($day);
        }
    }

    /** Order back up to the shelf level whatever has run down, one delivery per supplier. */
    protected function restock(Carbon $day): void
    {
        $onHand = DB::table('products')->whereIn('id', array_keys($this->products))->pluck('on_hand', 'id');
        $orders = [];

        foreach ($this->products as $product) {
            $have = (float) $onHand[$product['id']];

            if ($have > $product['target'] * 0.45) {
                continue;
            }

            // Prices creep up through the year, and each delivery differs a little.
            $cost = round($product['cost'] * (1 + 0.05 * $day->dayOfYear / 365) * mt_rand(97, 104) / 100, 2);

            $orders[$product['supplier']][] = [
                'product_id' => $product['id'],
                'quantity' => (int) ceil($product['target'] - $have),
                'unit_cost' => min($cost, round($product['price'] * 0.92, 2)),
            ];
        }

        foreach ($orders as $supplierId => $lines) {
            $this->as('admin');
            $this->tick(4, 15);

            $purchase = $this->attempt(fn () => $this->purchases->post([
                'supplier_id' => $supplierId,
                'purchase_date' => $day,
                'supplier_reference' => sprintf('DR-%s-%05d', $day->format('ym'), mt_rand(1, 99999)),
                'notes' => null,
            ], $lines));

            if ($purchase) {
                $this->stamp($purchase);
                $this->tally['purchases']++;
                $this->recentPurchases[] = $purchase->id;
                $this->recentPurchases = array_slice($this->recentPurchases, -20);
            }
        }
    }

    protected function aSale(Carbon $day, bool $isToday): void
    {
        $named = mt_rand(1, 100) <= 34 && $this->customers !== [];
        $customer = $named ? $this->pickCustomer() : null;
        $contractor = $customer && (float) $customer->credit_limit > 0;

        $lines = [];
        $onHand = DB::table('products')->whereIn('id', array_keys($this->products))->pluck('on_hand', 'id');

        foreach ($this->pickProducts($day, $this->weighted([1 => 35, 2 => 30, 3 => 20, 4 => 10, 5 => 5])) as $product) {
            $have = (float) $onHand[$product['id']];

            // A box of the product sold whole, in its other unit, now and then.
            if (isset($this->otherUnits[$product['id']]) && mt_rand(1, 4) === 1) {
                [$unitId, $factor, $boxPrice] = $this->otherUnits[$product['id']];

                if ($have >= $factor) {
                    $lines[] = ['product_id' => $product['id'], 'unit_id' => $unitId, 'quantity' => 1, 'unit_price' => $boxPrice];

                    continue;
                }
            }

            $quantity = min($this->quantityFor($product, $contractor), $product['decimals'] ? floor($have * 2) / 2 : floor($have));

            if ($quantity <= 0) {
                continue;
            }

            $lines[] = ['product_id' => $product['id'], 'quantity' => $this->number($quantity), 'unit_price' => $product['price']];
        }

        if ($lines === []) {
            return;
        }

        $subtotal = SaleTotals::of($lines, 0)['subtotal'];

        $discount = match (true) {
            $contractor && mt_rand(1, 4) === 1 => round($subtotal * mt_rand(2, 5) / 10000),
            $named && mt_rand(1, 12) === 1 => round($subtotal * 2 / 10000),
            ! $named && $subtotal >= 200000 && mt_rand(1, 15) === 1 => (float) mt_rand(1, 5) * 10,
            default => 0,
        };

        $total = SaleTotals::of($lines, $discount)['total'];

        [$onAccount, $payments] = $this->payFor($day, $customer, $total);

        $this->as($this->cashierFor($customer));
        $this->tick($isToday ? 2 : 6, $isToday ? 5 : 30);

        $sale = $this->attempt(fn () => $this->sales->post([
            'customer_id' => $customer?->id ?? $this->walkInId,
            'sale_date' => $day,
            'discount' => $discount,
            'on_account' => $onAccount / 100,
            'payments' => $payments,
            'notes' => null,
        ], $lines));

        if ($sale) {
            $this->stamp($sale);
            $this->tally['sales']++;
            $this->recentSales[] = $sale->id;
            $this->recentSales = array_slice($this->recentSales, -60);
        }
    }

    /**
     * How the sale is paid: what goes on account, in centavos, and the
     * payment rows the till would send.
     *
     * @return array{0:int, 1:list<array<string, mixed>>}
     */
    protected function payFor(Carbon $day, ?Customer $customer, int $total): array
    {
        if ($total <= 0) {
            return [0, []];
        }

        // On account, from October, for a customer with credit and room left.
        if ($customer && (float) $customer->credit_limit > 0 && $day->gte(Carbon::parse(self::CREDIT_FROM)) && mt_rand(1, 100) <= 55) {
            $down = mt_rand(1, 100) <= 30 ? (int) (floor($total * mt_rand(20, 50) / 100 / 10000) * 10000) : 0;
            $onAccount = $total - $down;

            if ($onAccount > 0 && CustomerCredit::refusal($customer, $onAccount) === null) {
                return [$onAccount, $down > 0 ? [$this->payment('Cash', $down)] : []];
            }
        }

        // A few pay part by GCash and the rest in cash.
        if ($total >= 50000 && mt_rand(1, 100) <= 5) {
            $part = (int) (floor($total * mt_rand(30, 60) / 100 / 100) * 100);

            return [0, [$this->payment('GCash', $part), $this->payment('Cash', $total - $part, $this->tendered($total - $part))]];
        }

        // GCash grows through the year; big orders go by bank.
        $gcash = 12 + (int) round(14 * $day->dayOfYear / 315);
        $method = $total >= 2000000
            ? $this->weighted(['Bank transfer' => 50, 'Cash' => 25, 'GCash' => 10, 'Card' => 15])
            : $this->weighted(['Cash' => 82 - $gcash, 'GCash' => $gcash, 'Maya' => 7, 'Card' => 6, 'Bank transfer' => 5]);

        if (! isset($this->methods[$method])) {
            $method = 'Cash';
        }

        return [0, [$this->payment($method, $method === 'Cash' ? $this->tendered($total) : $total)]];
    }

    protected function payment(string $method, int $cents, ?int $handed = null): array
    {
        $reference = match ($method) {
            'GCash' => '10'.mt_rand(10000, 99999).mt_rand(100000, 999999),
            'Maya' => 'MY'.mt_rand(1000000000, 9999999999),
            'Card' => 'AUTH-'.mt_rand(100000, 999999),
            'Bank transfer' => $this->pick(['BDO', 'BPI', 'Metrobank', 'UnionBank']).'-'.mt_rand(10000000, 99999999),
            default => null,
        };

        return [
            'payment_method_id' => $this->methods[$method],
            'amount' => ($handed ?? $cents) / 100,
            'reference' => $reference,
        ];
    }

    /** What a customer hands over for this much, in centavos. */
    protected function tendered(int $cents): int
    {
        $step = $this->weighted([0 => 35, 2000 => 10, 5000 => 15, 10000 => 20, 50000 => 12, 100000 => 8]);

        return $step === 0 ? $cents : (int) (ceil($cents / $step) * $step);
    }

    protected function aSalesReturn(Carbon $day): void
    {
        $sale = $this->recentSales === [] ? null : Sale::with('lines')->find($this->pick(array_slice($this->recentSales, -40)));

        if (! $sale || ! $sale->isPosted()) {
            return;
        }

        $line = $sale->lines->first(fn ($line) => (float) $line->returnable() > 0);

        if (! $line) {
            return;
        }

        $returnable = (float) $line->returnable();
        $quantity = $line->unit_id ? $returnable : min($returnable, (float) $this->pick([1, 1, 1, 2]));

        $this->as('encoder');
        $this->tick(3, 10);

        $return = $this->attempt(fn () => $this->salesReturns->post([
            'sale_id' => $sale->id,
            'return_date' => $day,
            'reason' => $this->pick(['Defective item', 'Wrong size', 'Wrong item picked', 'Not needed after all', 'Damaged in transit']),
            'notes' => null,
        ], [['sale_line_id' => $line->id, 'quantity' => $this->number($quantity)]]));

        if ($return) {
            $this->stamp($return);
            $this->tally['sales returns']++;
        }
    }

    protected function aPurchaseReturn(Carbon $day): void
    {
        $purchase = $this->recentPurchases === [] ? null : Purchase::with('lines')->find($this->pick($this->recentPurchases));

        if (! $purchase || ! $purchase->isPosted()) {
            return;
        }

        $line = $purchase->lines->shuffle()->first(fn ($line) => (float) $line->returnable() >= 1);

        if (! $line) {
            return;
        }

        $quantity = max(1, min((float) $line->returnable(), floor((float) $line->quantity * mt_rand(3, 10) / 100)));

        $this->as('admin');
        $this->tick(3, 10);

        $return = $this->attempt(fn () => $this->purchaseReturns->post([
            'purchase_id' => $purchase->id,
            'return_date' => $day,
            'reason' => $this->pick(['Damaged on delivery', 'Wrong item delivered', 'Short of specification', 'Defective batch']),
            'notes' => null,
        ], [['purchase_line_id' => $line->id, 'quantity' => $this->number($quantity)]]));

        if ($return) {
            $this->stamp($return);
            $this->tally['purchase returns']++;
        }
    }

    /** A breakage, or something found. */
    protected function anAdjustment(Carbon $day): void
    {
        $product = $this->pick(array_values($this->products));
        $have = (float) DB::table('products')->where('id', $product['id'])->value('on_hand');
        $found = mt_rand(1, 100) <= 20;
        $quantity = $product['decimals'] ? (float) $this->pick([0.5, 1, 1.5, 2]) : (float) mt_rand(1, 3);

        if (! $found && $have < $quantity) {
            return;
        }

        $this->as('admin');
        $this->tick(3, 10);

        $adjustment = $this->attempt(fn () => $this->adjustments->post(
            $found ? 'increase' : 'decrease',
            [['product_id' => $product['id'], 'quantity' => $this->number($quantity), 'unit_cost' => $found ? $product['cost'] : null]],
            $day,
            ...($found
                ? ['count', 'Found during a shelf check.']
                : $this->pick([['damaged', 'Broken while handling.'], ['damaged', 'Wet from a roof leak.'], ['lost', 'Missing from the display.'], ['write_off', 'Rusted, cannot be sold.']])),
        ));

        if ($adjustment) {
            $this->stamp($adjustment);
            $this->tally['adjustments']++;
        }
    }

    protected function stockCount(Carbon $day): void
    {
        $lines = [];
        $onHand = DB::table('products')->whereIn('id', array_keys($this->products))->pluck('on_hand', 'id');

        foreach ($this->some(array_values($this->products), mt_rand(3, 6)) as $product) {
            $quantity = $product['decimals'] ? 1.5 : (float) mt_rand(1, 4);

            if ((float) $onHand[$product['id']] >= $quantity) {
                $lines[] = ['product_id' => $product['id'], 'quantity' => $this->number($quantity), 'unit_cost' => null];
            }
        }

        if ($lines === []) {
            return;
        }

        $this->as('admin');
        $this->tick(30, 60);

        $adjustment = $this->attempt(fn () => $this->adjustments->post('decrease', $lines, $day, 'count', 'Quarter-end stock count.'));

        if ($adjustment) {
            $this->stamp($adjustment);
            $this->tally['adjustments']++;
        }
    }

    /**
     * Two documents entered in error this morning, and voided. Last, and
     * dated today, because a void moves stock on the day it is voided.
     */
    protected function mistakes(Carbon $today): void
    {
        $product = collect($this->products)->first(fn ($p) => $p['unit'] === 'pcs' && $p['price'] < 500);
        $have = (float) DB::table('products')->where('id', $product['id'])->value('on_hand');

        $this->as('encoder');
        $this->tick(2, 4);

        if ($have >= 2) {
            $sale = $this->attempt(fn () => $this->sales->post([
                'customer_id' => $this->walkInId,
                'sale_date' => $today,
                'discount' => 0,
            ], [['product_id' => $product['id'], 'quantity' => 2, 'unit_price' => $product['price']]]));

            if ($sale) {
                $this->stamp($sale);
                $this->sales->void($sale, 'Encoded twice at the counter.');
                $this->tally['sales']++;
                $this->tally['voids']++;
            }
        }

        $this->as('admin');

        $purchase = $this->attempt(fn () => $this->purchases->post([
            'supplier_id' => $product['supplier'],
            'purchase_date' => $today,
            'supplier_reference' => 'DR-'.$today->format('ym').'-00000',
        ], [['product_id' => $product['id'], 'quantity' => 24, 'unit_cost' => $product['cost']]]));

        if ($purchase) {
            $this->stamp($purchase);
            $this->purchases->void($purchase, 'Duplicate of the supplier\'s earlier delivery receipt.');
            $this->tally['purchases']++;
            $this->tally['voids']++;
        }
    }

    /* ----------------------------------------------------------------- Tools */

    /** Post, or skip the document if the poster refuses it. */
    protected function attempt(callable $post): ?Model
    {
        try {
            return $post();
        } catch (InventoryException|PaymentException $e) {
            $this->tally['skipped']++;

            return null;
        }
    }

    protected function as(string $who): void
    {
        auth()->setUser($this->staff[$who]);
    }

    protected function cashierFor(?Customer $customer): string
    {
        return $customer && (float) $customer->credit_limit > 0
            ? $this->weighted(['admin' => 30, 'encoder' => 45, 'cashier' => 25])
            : $this->weighted(['admin' => 10, 'encoder' => 45, 'cashier' => 45]);
    }

    protected function pickCustomer(): Customer
    {
        $weights = [];

        foreach ($this->customers as $id => $customer) {
            $weights[$id] = (float) $customer->credit_limit > 0 ? 3 : 1;
        }

        return $this->customers[$this->weighted($weights)];
    }

    /** Products for one sale: cheap things sell often, and the season leans on some. */
    protected function pickProducts(Carbon $day, int $howMany): array
    {
        $summer = in_array($day->month, [3, 4, 5], true);
        $rains = in_array($day->month, [6, 7, 8, 9], true);
        $weights = [];

        foreach ($this->products as $id => $product) {
            $weight = 100 / sqrt(max($product['price'], 4));

            if ($summer && in_array($product['category'], ['Fans & Ventilation', 'Cement & Aggregates', 'Steel & Rebar', 'Lumber & Plywood', 'Paints & Coatings'], true)) {
                $weight *= 2.2;
            }

            if ($rains && in_array($product['category'], ['Roofing & Gutters', 'Water Pumps & Tanks', 'Plumbing', 'Adhesives & Sealants'], true)) {
                $weight *= 2.2;
            }

            $weights[$id] = $weight;
        }

        $picked = [];

        while (count($picked) < $howMany && count($picked) < count($weights)) {
            $picked[$this->weighted($weights)] = true;
        }

        return array_map(fn ($id) => $this->products[$id], array_keys($picked));
    }

    protected function quantityFor(array $product, bool $contractor): float
    {
        $quantity = match (true) {
            $product['unit'] === 'm' => (float) $this->pick([1, 2, 2.5, 3, 5, 5, 10, 10, 12.5, 15, 20, 25, 50]),
            $product['unit'] === 'kg' => (float) $this->pick([0.5, 1, 1, 1.5, 2, 2.5, 3, 5]),
            $product['unit'] === 'cu.m' => (float) $this->pick([0.5, 1, 1, 1.5, 2, 3]),
            $product['price'] <= 30 => (float) mt_rand(5, 40),
            $product['price'] <= 100 => (float) mt_rand(1, 10),
            $product['price'] <= 500 => (float) mt_rand(1, 4),
            default => (float) $this->weighted([1 => 80, 2 => 20]),
        };

        return $contractor ? $quantity * $this->pick([2, 3, 4]) : $quantity;
    }

    /** A quantity as the forms send it: 10, or 2.5. */
    protected function number(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }

    protected function startClock(Carbon $day, int $hour, int $minute): void
    {
        $this->clock = $day->copy()->setTime($hour, $minute);
    }

    /** Move the shop's clock on a few minutes, never past now. */
    protected function tick(int $min, int $max): void
    {
        $this->clock->addMinutes(mt_rand($min, $max));

        if ($this->clock->gt(now())) {
            $this->clock = now()->subMinute();
        }
    }

    /** The document was keyed when the shop's clock says, not this morning. */
    protected function stamp(Model $document): void
    {
        $at = $this->clock->copy();
        $columns = ['created_at' => $at, 'updated_at' => $at];

        if (in_array('posted_at', $document->getFillable(), true) || array_key_exists('posted_at', $document->getAttributes())) {
            $columns['posted_at'] = $at;
        }

        DB::table($document->getTable())->where('id', $document->getKey())->update($columns);
    }

    protected function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    protected function some(array $items, int $howMany): array
    {
        return array_map(fn ($key) => $items[$key], (array) array_rand($items, min($howMany, count($items))));
    }

    /** One key of `$weights`, drawn in proportion to its weight. */
    protected function weighted(array $weights): mixed
    {
        $roll = mt_rand(1, 1000000) / 1000000 * array_sum($weights);

        foreach ($weights as $key => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
